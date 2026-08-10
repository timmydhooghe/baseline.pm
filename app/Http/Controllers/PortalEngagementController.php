<?php

namespace App\Http\Controllers;

use App\Enums\BaselineItemType;
use App\Enums\BaselineStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\DeliverableStatus;
use App\Enums\EngagementStatus;
use App\Enums\FinalAcceptanceStatus;
use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\Decision;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Report;
use App\Models\Risk;
use App\Models\Stakeholder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One engagement as the customer sees it (FA-27): shared records only —
 * scope and progress, milestones, records awaiting their decision, shared
 * decisions and risks, what they owe, and the published report ledger. The
 * projection is built structurally from customer-visible fields; cost, rate
 * and margin keys are never present, not merely blanked. Review pages stay
 * on personally signed links, minted here for the signed-in stakeholder.
 */
class PortalEngagementController extends Controller
{
    /**
     * Show the engagement hub.
     */
    public function show(Request $request, string $engagement): Response
    {
        $stakeholder = $request->user('stakeholder');

        if (! $stakeholder instanceof Stakeholder) {
            abort(403);
        }

        /*
         * Resolved through the stakeholder's own customer, so an engagement
         * of any other customer or organization is a 404 by construction.
         */
        $engagement = $stakeholder->customer->engagements()
            ->whereIn('status', EngagementStatus::portalVisible())
            ->with([
                'baselines.items',
                'deliverables.baselineItem',
                'deliverables.milestoneItem',
                'changeRequests',
                'decisions',
                'risks',
                'dependencies.responsibleStakeholder',
                'reports',
                'finalAcceptances',
            ])
            ->findOrFail($engagement);

        $approved = $engagement->approvedBaseline();

        return Inertia::render('portal/engagement', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'customer' => [
                'name' => $stakeholder->customer->name,
            ],
            'organization' => [
                'name' => $stakeholder->organization->name,
            ],
            'stakeholder' => [
                'name' => $stakeholder->name,
                'roleLabel' => $stakeholder->role->label(),
                'canApprove' => $stakeholder->role->canApprove(),
            ],
            'baseline' => $approved === null ? null : [
                'version' => $approved->version,
                'commercialModel' => $approved->commercial_model->label(),
                'contractValue' => $approved->contract_value->toArray(),
                'startDate' => $approved->start_date->toFormattedDateString(),
                'endDate' => $approved->end_date->toFormattedDateString(),
                'approvedAt' => $approved->approved_at?->toFormattedDateString(),
            ],
            'actions' => $this->actions($engagement, $stakeholder),
            'scope' => [
                'deliverables' => $engagement->deliverables
                    ->sortBy(fn (Deliverable $deliverable): int => $deliverable->baselineItem->position)
                    ->values()
                    ->map(fn (Deliverable $deliverable): array => [
                        'id' => $deliverable->id,
                        'title' => $deliverable->baselineItem->title,
                        'description' => $deliverable->baselineItem->description,
                        'status' => $deliverable->status->value,
                        'statusLabel' => $deliverable->status->label(),
                        'progress' => $deliverable->progress,
                        'forecastDate' => $deliverable->forecast_date?->toFormattedDateString(),
                        'milestone' => $deliverable->milestoneItem?->title,
                        'value' => $deliverable->baselineItem->value?->toArray(),
                        'acceptedAt' => $deliverable->accepted_at?->toFormattedDateString(),
                    ]),
                'acceptedValue' => $engagement->acceptedValue()->toArray(),
            ],
            'milestones' => $approved === null ? [] : $approved->items
                ->where('type', BaselineItemType::Milestone)
                ->values()
                ->map(fn (BaselineItem $milestone): array => [
                    'id' => $milestone->id,
                    'title' => $milestone->title,
                    'baselineDate' => $milestone->baseline_date?->toFormattedDateString(),
                    'paymentTrigger' => $milestone->payment_trigger,
                    'deliverables' => [
                        'accepted' => $engagement->deliverables
                            ->filter(fn (Deliverable $deliverable): bool => $deliverable->milestone_item_id === $milestone->id
                                && $deliverable->status === DeliverableStatus::Accepted)
                            ->count(),
                        'total' => $engagement->deliverables
                            ->filter(fn (Deliverable $deliverable): bool => $deliverable->milestone_item_id === $milestone->id)
                            ->count(),
                    ],
                ]),
            'changeRequests' => $engagement->changeRequests
                ->filter(fn (ChangeRequest $changeRequest): bool => in_array($changeRequest->status, [
                    ChangeRequestStatus::AwaitingApproval,
                    ChangeRequestStatus::Approved,
                    ChangeRequestStatus::Rejected,
                ], true))
                ->sortByDesc('submitted_at')
                ->values()
                ->map(fn (ChangeRequest $changeRequest): array => [
                    'id' => $changeRequest->id,
                    'title' => $changeRequest->title,
                    'status' => $changeRequest->status->value,
                    'statusLabel' => $changeRequest->status->label(),
                    'price' => $changeRequest->customer_price?->toArray(),
                    'submittedAt' => $changeRequest->submitted_at?->toFormattedDateString(),
                    'decidedAt' => $changeRequest->decided_at?->toFormattedDateString(),
                ]),
            'decisions' => $engagement->decisions
                ->filter(fn (Decision $decision): bool => $decision->visibility->isShared()
                    && $decision->status->isConfirmed()
                    && $decision->customer_snapshot_id !== null)
                ->sortByDesc('decided_on')
                ->values()
                ->map(fn (Decision $decision): array => [
                    'id' => $decision->id,
                    'title' => $decision->title,
                    'statusLabel' => $decision->status->label(),
                    'decidedOn' => $decision->decided_on?->toFormattedDateString(),
                    'acknowledgedAt' => $decision->acknowledged_at?->toFormattedDateString(),
                    'acknowledgedBy' => $decision->acknowledged_by_name,
                    'url' => URL::signedRoute('portal.decisions.show', [
                        'decision' => $decision->id,
                        'stakeholder' => $stakeholder->id,
                        'snapshot' => $decision->customer_snapshot_id,
                    ]),
                ]),
            'risks' => $engagement->risks
                ->filter(fn (Risk $risk): bool => $risk->visibility->isShared() && $risk->status->isLive())
                ->sortByDesc(fn (Risk $risk): int => $risk->score())
                ->values()
                ->map(fn (Risk $risk): array => [
                    'id' => $risk->id,
                    'title' => $risk->title,
                    'description' => $risk->description,
                    'probability' => $risk->probability->label(),
                    'impact' => $risk->impact->label(),
                    'statusLabel' => $risk->status->label(),
                    'mitigation' => $risk->mitigation,
                ]),
            'dependencies' => $engagement->customerOwedDependencies()
                ->map(fn (Dependency $dependency): array => [
                    'id' => $dependency->id,
                    'title' => $dependency->title,
                    'description' => $dependency->description,
                    'responsible' => $dependency->responsibleName(),
                    'requiredOn' => $dependency->required_on->toFormattedDateString(),
                    'late' => $dependency->isLate(),
                    'delayDays' => $dependency->delayDays(),
                    'statusLabel' => $dependency->status->label(),
                ])
                ->values(),
            'reports' => $engagement->reports
                ->sortByDesc('week_start')
                ->values()
                ->map(fn (Report $report): array => [
                    'id' => $report->id,
                    'label' => $report->label(),
                    'publishedAt' => $report->published_at->toFormattedDateString(),
                    'url' => $report->customer_snapshot_id === null ? null : URL::signedRoute('portal.reports.show', [
                        'report' => $report->id,
                        'stakeholder' => $stakeholder->id,
                        'snapshot' => $report->customer_snapshot_id,
                    ]),
                ]),
        ]);
    }

    /**
     * What awaits the customer's decision, oldest commitments first: a
     * submitted baseline, proposed changes, deliverables under review and an
     * open final acceptance. Review links are personally signed for the
     * signed-in stakeholder and only minted when their role may respond —
     * viewers see that a decision is pending, not a door they cannot open.
     *
     * @return list<array<string, mixed>>
     */
    private function actions(Engagement $engagement, Stakeholder $stakeholder): array
    {
        $canApprove = $stakeholder->role->canApprove();
        $actions = [];

        $open = $engagement->openBaseline();

        if ($open?->status === BaselineStatus::AwaitingApproval && $open->customer_snapshot_id !== null) {
            $actions[] = [
                'type' => 'baseline',
                'title' => __('Baseline v:version', ['version' => $open->version]),
                'description' => __('The scope, milestones and commercial commitments this engagement will run against.'),
                'respondBy' => null,
                'overdue' => false,
                'url' => $canApprove ? URL::signedRoute('portal.baselines.show', [
                    'baseline' => $open->id,
                    'stakeholder' => $stakeholder->id,
                    'snapshot' => $open->customer_snapshot_id,
                ]) : null,
            ];
        }

        foreach ($engagement->changeRequests as $changeRequest) {
            if ($changeRequest->status !== ChangeRequestStatus::AwaitingApproval || $changeRequest->customer_snapshot_id === null) {
                continue;
            }

            $actions[] = [
                'type' => 'change_request',
                'title' => $changeRequest->title,
                'description' => __('A proposed change at :price.', [
                    'price' => $changeRequest->customer_price?->format() ?? '—',
                ]),
                'respondBy' => $changeRequest->respond_by?->toFormattedDateString(),
                'overdue' => $changeRequest->respond_by?->isPast() ?? false,
                'url' => $canApprove ? URL::signedRoute('portal.change-requests.show', [
                    'changeRequest' => $changeRequest->id,
                    'stakeholder' => $stakeholder->id,
                    'snapshot' => $changeRequest->customer_snapshot_id,
                ]) : null,
            ];
        }

        foreach ($engagement->deliverables as $deliverable) {
            if ($deliverable->status !== DeliverableStatus::AwaitingAcceptance || $deliverable->customer_snapshot_id === null) {
                continue;
            }

            $actions[] = [
                'type' => 'deliverable',
                'title' => $deliverable->baselineItem->title,
                'description' => __('Submitted for acceptance — accepting is signing.'),
                'respondBy' => $deliverable->respond_by?->toFormattedDateString(),
                'overdue' => $deliverable->respond_by?->isPast() ?? false,
                'url' => $canApprove ? URL::signedRoute('portal.deliverables.show', [
                    'deliverable' => $deliverable->id,
                    'stakeholder' => $stakeholder->id,
                    'snapshot' => $deliverable->customer_snapshot_id,
                ]) : null,
            ];
        }

        $finalAcceptance = $engagement->currentFinalAcceptance();

        if ($finalAcceptance?->status === FinalAcceptanceStatus::AwaitingResponse && $finalAcceptance->customer_snapshot_id !== null) {
            $actions[] = [
                'type' => 'final_acceptance',
                'title' => __('Final acceptance'),
                'description' => __('The engagement-level sign-off that completes :name.', ['name' => $engagement->name]),
                'respondBy' => $finalAcceptance->respond_by?->toFormattedDateString(),
                'overdue' => $finalAcceptance->respond_by?->isPast() ?? false,
                'url' => $canApprove ? URL::signedRoute('portal.final-acceptances.show', [
                    'finalAcceptance' => $finalAcceptance->id,
                    'stakeholder' => $stakeholder->id,
                    'snapshot' => $finalAcceptance->customer_snapshot_id,
                ]) : null,
            ];
        }

        return $actions;
    }
}
