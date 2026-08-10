<?php

namespace App\Http\Controllers;

use App\Enums\BaselineItemType;
use App\Enums\ChangeRequestStatus;
use App\Enums\DeliverableStatus;
use App\Enums\EngagementStatus;
use App\Enums\FinalAcceptanceStatus;
use App\Models\BaselineItem;
use App\Models\BurnWeek;
use App\Models\Engagement;
use App\Models\RateCardVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Today (FA-25): the exception dashboard. Only what needs attention crosses
 * it — scope creep with its € at risk, change requests awaiting the
 * customer's decision, late dependencies with their impact, escalated risks,
 * unrecorded burn weeks and unpublished report drafts. Engagements with
 * nothing to flag are summarized in one quiet line each, and the rail keeps
 * the calendar honest: upcoming milestones and what the customer owes.
 *
 * Scope creep pricing, risk exposure and the burn and report queues follow
 * the visibility rules they have everywhere else: commercial figures for the
 * roles that read the rate card, governance queues for the managing roles.
 */
class TodayController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('viewAny', Engagement::class);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $withCommercials = $user->can('viewAny', RateCardVersion::class);
        $managesGovernance = $user->role->isManager();

        $engagements = Engagement::query()
            ->whereIn('status', [EngagementStatus::Active, EngagementStatus::AwaitingFinalAcceptance])
            ->with('customer')
            ->orderBy('name')
            ->get();

        $sections = [
            'scopeCreep' => [],
            'changeRequests' => [],
            'lateDependencies' => [],
            'escalatedRisks' => [],
            'unrecordedBurn' => [],
            'reportDrafts' => [],
        ];
        $quiet = [];
        $milestones = [];
        $customerActions = [];

        foreach ($engagements as $engagement) {
            $flagged = $this->collectExceptions($engagement, $sections, $withCommercials, $managesGovernance);

            if (! $flagged) {
                $quiet[] = $this->quietLine($engagement);
            }

            $milestones = [...$milestones, ...$this->milestones($engagement)];
            $customerActions = [...$customerActions, ...$this->customerActions($engagement)];
        }

        usort($milestones, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        usort($customerActions, fn (array $a, array $b): int => strcmp($a['due'] ?? '9999-12-31', $b['due'] ?? '9999-12-31'));

        return Inertia::render('dashboard', [
            'sections' => $sections,
            'quiet' => $quiet,
            'milestones' => array_slice($milestones, 0, 8),
            'customerActions' => array_slice($customerActions, 0, 10),
            'engagementCount' => $engagements->count(),
            'can' => [
                'viewCommercials' => $withCommercials,
                'manageGovernance' => $managesGovernance,
            ],
        ]);
    }

    /**
     * Everything this engagement needs somebody to look at today, appended
     * to the cross-engagement sections. Returns whether anything was.
     *
     * @param  array<string, list<array<string, mixed>>>  $sections
     */
    private function collectExceptions(Engagement $engagement, array &$sections, bool $withCommercials, bool $managesGovernance): bool
    {
        $reference = [
            'engagementId' => $engagement->id,
            'engagementName' => $engagement->name,
        ];
        $flagged = false;

        $creep = $engagement->unbilledRisk();

        if ($creep['count'] > 0) {
            $flagged = true;
            $sections['scopeCreep'][] = [
                ...$reference,
                'count' => $creep['count'],
                'unpriced' => $creep['unpriced'],
                'price' => $withCommercials ? $creep['price']->toArray() : null,
            ];
        }

        $awaiting = $engagement->changeRequests()
            ->where('status', ChangeRequestStatus::AwaitingApproval)
            ->orderBy('respond_by')
            ->get();

        foreach ($awaiting as $changeRequest) {
            $flagged = true;
            $sections['changeRequests'][] = [
                ...$reference,
                'id' => $changeRequest->id,
                'title' => $changeRequest->title,
                'price' => $changeRequest->customer_price?->toArray(),
                'respondBy' => $changeRequest->respond_by?->toFormattedDateString(),
                'overdue' => $changeRequest->respond_by?->isPast() ?? false,
            ];
        }

        foreach ($engagement->lateDependencies() as $dependency) {
            $flagged = true;
            $impact = $dependency->projectedImpact();
            $sections['lateDependencies'][] = [
                ...$reference,
                'id' => $dependency->id,
                'title' => $dependency->title,
                'responsible' => $dependency->responsibleName(),
                'party' => $dependency->party->value,
                'partyLabel' => $dependency->party->label(),
                'requiredOn' => $dependency->required_on->toFormattedDateString(),
                'delayDays' => $dependency->delayDays(),
                'impact' => array_slice($impact, 0, 2),
                'impactCount' => count($impact),
            ];
        }

        foreach ($engagement->escalatedRisks() as $risk) {
            $flagged = true;
            $sections['escalatedRisks'][] = [
                ...$reference,
                'id' => $risk->id,
                'title' => $risk->title,
                'rating' => $risk->probability->label().' × '.$risk->impact->label(),
                'worsening' => $risk->isWorsening(),
                'exposure' => $withCommercials ? $risk->exposure()->toArray() : null,
            ];
        }

        if ($managesGovernance) {
            $unrecorded = $engagement->unrecordedBurnWeeks();

            if ($unrecorded !== []) {
                $flagged = true;
                $sections['unrecordedBurn'][] = [
                    ...$reference,
                    'count' => count($unrecorded),
                    'oldestWeekStart' => $unrecorded[0]->toDateString(),
                    'oldestWeekLabel' => BurnWeek::labelFor($unrecorded[0]),
                ];
            }

            $due = $engagement->dueReportWeeks();

            if ($due !== []) {
                $flagged = true;
                $latest = $due[count($due) - 1];
                $sections['reportDrafts'][] = [
                    ...$reference,
                    'count' => count($due),
                    'latestWeekStart' => $latest->toDateString(),
                    'latestWeekLabel' => BurnWeek::labelFor($latest),
                ];
            }
        }

        return $flagged;
    }

    /**
     * The one line a quiet engagement gets: where it stands, so quiet reads
     * as "nothing needs you" rather than "nothing is known".
     *
     * @return array<string, mixed>
     */
    private function quietLine(Engagement $engagement): array
    {
        $baseline = $engagement->approvedBaseline();
        $accepted = $engagement->deliverables()->where('status', DeliverableStatus::Accepted)->count();
        $total = $engagement->deliverables()->count();

        return [
            'id' => $engagement->id,
            'name' => $engagement->name,
            'customerName' => $engagement->customer->name,
            'statusLabel' => $engagement->status->label(),
            'line' => $baseline === null
                ? __('No approved baseline yet.')
                : __('Baseline v:version · :accepted of :total deliverables accepted', [
                    'version' => $baseline->version,
                    'accepted' => $accepted,
                    'total' => $total,
                ]),
        ];
    }

    /**
     * The rail's calendar: the approved baseline's dated milestones that are
     * still ahead, plus past-dated ones with deliverables still unsigned —
     * a milestone whose work is all accepted has left the calendar.
     *
     * @return list<array<string, mixed>>
     */
    private function milestones(Engagement $engagement): array
    {
        $baseline = $engagement->approvedBaseline();

        if ($baseline === null) {
            return [];
        }

        $openByMilestone = $engagement->deliverables()
            ->whereNot('status', DeliverableStatus::Accepted)
            ->whereNotNull('milestone_item_id')
            ->get()
            ->countBy('milestone_item_id');

        return array_values($baseline->items()
            ->where('type', BaselineItemType::Milestone)
            ->whereNotNull('baseline_date')
            ->orderBy('baseline_date')
            ->get()
            ->map(function (BaselineItem $item) use ($engagement, $openByMilestone): ?array {
                $open = (int) ($openByMilestone[$item->id] ?? 0);
                $past = $item->baseline_date !== null && $item->baseline_date->isPast();

                if ($past && $open === 0) {
                    return null;
                }

                return [
                    'engagementId' => $engagement->id,
                    'engagementName' => $engagement->name,
                    'id' => $item->id,
                    'title' => $item->title,
                    'date' => $item->baseline_date?->toDateString(),
                    'dateLabel' => $item->baseline_date?->toFormattedDateString(),
                    'overdue' => $past,
                    'openDeliverables' => $open,
                ];
            })
            ->filter()
            ->all());
    }

    /**
     * What the customer owes, across every channel that can owe something:
     * dependencies they are responsible for, change requests and deliverables
     * awaiting their decision, and an open final acceptance.
     *
     * @return list<array<string, mixed>>
     */
    private function customerActions(Engagement $engagement): array
    {
        $actions = [];

        foreach ($engagement->customerOwedDependencies() as $dependency) {
            $actions[] = $this->customerAction($engagement, 'dependency', $dependency->id, $dependency->title, $dependency->required_on, $dependency->isLate(), $dependency->responsibleName());
        }

        $awaiting = $engagement->changeRequests()
            ->where('status', ChangeRequestStatus::AwaitingApproval)
            ->get();

        foreach ($awaiting as $changeRequest) {
            $actions[] = $this->customerAction($engagement, 'change_request', $changeRequest->id, $changeRequest->title, $changeRequest->respond_by, $changeRequest->respond_by?->isPast() ?? false);
        }

        $submitted = $engagement->deliverables()
            ->where('status', DeliverableStatus::AwaitingAcceptance)
            ->with('baselineItem')
            ->get();

        foreach ($submitted as $deliverable) {
            $actions[] = $this->customerAction($engagement, 'deliverable', $deliverable->id, $deliverable->baselineItem->title, $deliverable->respond_by, $deliverable->respond_by?->isPast() ?? false);
        }

        $finalAcceptance = $engagement->currentFinalAcceptance();

        if ($finalAcceptance?->status === FinalAcceptanceStatus::AwaitingResponse) {
            $actions[] = $this->customerAction($engagement, 'final_acceptance', $engagement->id, __('Final acceptance'), $finalAcceptance->respond_by, $finalAcceptance->respond_by?->isPast() ?? false);
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    private function customerAction(Engagement $engagement, string $kind, string $id, string $title, CarbonImmutable|Carbon|null $due, bool $overdue, ?string $responsible = null): array
    {
        return [
            'engagementId' => $engagement->id,
            'engagementName' => $engagement->name,
            'kind' => $kind,
            'id' => $id,
            'title' => $title,
            'responsible' => $responsible,
            'due' => $due?->toDateString(),
            'dueLabel' => $due?->toFormattedDateString(),
            'overdue' => $overdue,
        ];
    }
}
