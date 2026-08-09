<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestStatus;
use App\Http\Requests\ChangeRequests\StoreChangeRequestRequest;
use App\Http\Requests\ChangeRequests\TransitionChangeRequestRequest;
use App\Http\Requests\ChangeRequests\UpdateChangeRequestRequest;
use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestAllocation;
use App\Models\ChangeRequestResponse;
use App\Models\Engagement;
use App\Models\RateCardRole;
use App\Models\RateCardVersion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChangeRequestController extends Controller
{
    /**
     * The change control ledger of an engagement (FA-11): every request with
     * its lifecycle state, commercial terms and decision trail.
     */
    public function index(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $viewCommercials = $user->can('viewAny', RateCardVersion::class);

        $changeRequests = $engagement->changeRequests()
            ->with(['workItem', 'mintedBaseline'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('engagements/change-requests', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'changeRequests' => $changeRequests
                ->map(fn (ChangeRequest $changeRequest): array => [
                    'id' => $changeRequest->id,
                    'title' => $changeRequest->title,
                    'status' => $changeRequest->status->value,
                    'statusLabel' => $changeRequest->status->label(),
                    'originLabel' => $changeRequest->origin?->label(),
                    'breachRisk' => $changeRequest->flagsContractualBreach(),
                    'price' => $changeRequest->customer_price?->toArray(),
                    'estimatedDays' => $changeRequest->estimated_days,
                    'respondBy' => $changeRequest->respond_by?->toFormattedDateString(),
                    'respondByOverdue' => $changeRequest->status === ChangeRequestStatus::AwaitingApproval
                        && ($changeRequest->respond_by?->isPast() ?? false),
                    'decidedAt' => $changeRequest->decided_at?->toFormattedDateString(),
                    'mintedBaselineVersion' => $changeRequest->mintedBaseline?->version,
                    'createdAt' => $changeRequest->created_at?->toFormattedDateString(),
                    'workItemKey' => $changeRequest->workItem?->external_key,
                ])
                ->values(),
            'position' => $engagement->positionSummary($viewCommercials),
            'can' => [
                'create' => $user->can('create', [ChangeRequest::class, $engagement]),
            ],
        ]);
    }

    /**
     * Raise a change request by hand (FA-11) — steering calls and emails
     * surface changes too, not just scope creep.
     */
    public function store(StoreChangeRequestRequest $request, Engagement $engagement): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $changeRequest = $engagement->draftChangeRequest($request->validated(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Change request drafted.')]);

        return to_route('change-requests.show', $changeRequest);
    }

    /**
     * One change request with everything a decision needs (FA-12): the
     * narrative, the scope creep evidence, the structured assessment priced at the
     * pinned rate card version, derived commercials, and the decision trail.
     */
    public function show(Request $request, ChangeRequest $changeRequest): Response
    {
        Gate::authorize('view', $changeRequest);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $changeRequest->load([
            'allocations.role', 'affectedItems', 'responses', 'impactMilestone',
            'workItem', 'mintedBaseline', 'createdBy', 'rateCardVersion', 'engagement',
        ]);

        $engagement = $changeRequest->engagement;
        $approvedBaseline = $engagement->approvedBaseline();

        /*
         * Cost, rates and margin follow the rate card policy (like the
         * baseline and triage pages): roles without it get the change
         * request with every commercial field structurally absent. The
         * customer price stays — the portal shows it to the customer anyway.
         */
        $viewCommercials = $user->can('viewAny', RateCardVersion::class);

        $rateCardVersionId = $changeRequest->rate_card_version_id ?? $approvedBaseline?->rate_card_version_id;
        $roles = ! $viewCommercials || $rateCardVersionId === null
            ? collect()
            : RateCardRole::query()->where('rate_card_version_id', $rateCardVersionId)->orderBy('position')->get();

        $canUpdate = $user->can('update', $changeRequest);

        return Inertia::render('change-requests/show', [
            'changeRequest' => $this->changeRequestViewModel($changeRequest),
            'assessment' => [
                'allocations' => $changeRequest->allocations
                    ->map(fn (ChangeRequestAllocation $allocation): array => [
                        'id' => $allocation->id,
                        'rateCardRoleId' => $allocation->rate_card_role_id,
                        'roleName' => $allocation->role->name,
                        'days' => $allocation->days,
                        'costPerDay' => $viewCommercials ? $allocation->role->cost_per_day->toArray() : null,
                        'cost' => $viewCommercials ? $allocation->cost()->toArray() : null,
                    ])
                    ->values(),
                'affectedItemIds' => $changeRequest->affectedItems->pluck('id')->values(),
                'cost' => ! $viewCommercials || $changeRequest->allocations->isEmpty() ? null : $changeRequest->cost()->toArray(),
                'suggestedPrice' => $viewCommercials ? $changeRequest->suggestedPrice()?->toArray() : null,
                'margin' => $viewCommercials ? $changeRequest->margin()?->toArray() : null,
                'marginPercent' => $viewCommercials ? $changeRequest->marginPercent() : null,
            ],
            'roles' => $roles
                ->map(fn (RateCardRole $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'costPerDay' => $role->cost_per_day->toArray(),
                    'sellPerDay' => $role->sell_per_day->toArray(),
                ])
                ->values(),
            'baselineItems' => ($approvedBaseline->items ?? collect())
                ->map(fn (BaselineItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->type->value,
                    'typeLabel' => $item->type->label(),
                    'title' => $item->title,
                    'baselineDate' => $item->baseline_date?->toFormattedDateString(),
                ])
                ->values(),
            'responses' => $changeRequest->responses
                ->map(fn (ChangeRequestResponse $response): array => [
                    'id' => $response->id,
                    'decision' => $response->decision->value,
                    'decisionLabel' => $response->decision->label(),
                    'stakeholderName' => $response->stakeholder_name,
                    'comment' => $response->comment,
                    'respondedAt' => $response->created_at->toFormattedDateString(),
                ])
                ->values(),
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'baselineVersion' => $approvedBaseline?->version,
            'position' => $engagement->positionSummary($viewCommercials),
            'can' => [
                'update' => $canUpdate,
                'viewCommercials' => $viewCommercials,
                'startAssessment' => $canUpdate && $changeRequest->status->canTransitionTo(ChangeRequestStatus::UnderAssessment),
                'moveToProposal' => $canUpdate && $changeRequest->status->canTransitionTo(ChangeRequestStatus::CustomerProposal),
                'submit' => $canUpdate && $changeRequest->status->canTransitionTo(ChangeRequestStatus::AwaitingApproval),
            ],
        ]);
    }

    /**
     * Update the narrative fields of an open change request.
     */
    public function update(UpdateChangeRequestRequest $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $changeRequest->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Change request saved.')]);

        return to_route('change-requests.show', $changeRequest);
    }

    /**
     * Move a change request along its internal lifecycle: open the
     * assessment (also the way back from a proposal or a clarification) or
     * move to the customer proposal stage.
     */
    public function transition(TransitionChangeRequestRequest $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $target = ChangeRequestStatus::from((string) $request->validated()['status']);

        match ($target) {
            ChangeRequestStatus::UnderAssessment => $changeRequest->startAssessment($user),
            ChangeRequestStatus::CustomerProposal => $changeRequest->moveToProposal($user),
            default => abort(422),
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Change request moved to :status.', [
            'status' => $changeRequest->status->label(),
        ])]);

        return to_route('change-requests.show', $changeRequest);
    }

    /**
     * @return array<string, mixed>
     */
    private function changeRequestViewModel(ChangeRequest $changeRequest): array
    {
        return [
            'id' => $changeRequest->id,
            'title' => $changeRequest->title,
            'what' => $changeRequest->what,
            'why' => $changeRequest->why,
            'origin' => $changeRequest->origin?->value,
            'originLabel' => $changeRequest->origin?->label(),
            'status' => $changeRequest->status->value,
            'statusLabel' => $changeRequest->status->label(),
            'estimatedDays' => $changeRequest->estimated_days,
            'loggedHours' => $changeRequest->logged_seconds > 0 ? round($changeRequest->logged_seconds / 3600, 1) : null,
            'workStartedAt' => $changeRequest->work_started_at?->toFormattedDateString(),
            'breachRisk' => $changeRequest->flagsContractualBreach(),
            'workItem' => $changeRequest->workItem === null ? null : [
                'id' => $changeRequest->workItem->id,
                'title' => $changeRequest->workItem->title,
                'externalKey' => $changeRequest->workItem->external_key,
                'externalUrl' => $changeRequest->workItem->external_url,
            ],
            'customerPrice' => $changeRequest->customer_price?->toArray(),
            'impactMilestoneId' => $changeRequest->impact_milestone_id,
            'impactDays' => $changeRequest->impact_days,
            'scopeAdded' => $changeRequest->scope_added,
            'scopeRemoved' => $changeRequest->scope_removed,
            'alternatives' => $changeRequest->alternatives,
            'rateCardVersion' => $changeRequest->rateCardVersion?->version,
            'submittedAt' => $changeRequest->submitted_at?->toFormattedDateString(),
            'respondBy' => $changeRequest->respond_by?->toFormattedDateString(),
            'respondByOverdue' => $changeRequest->status === ChangeRequestStatus::AwaitingApproval
                && ($changeRequest->respond_by?->isPast() ?? false),
            'decidedAt' => $changeRequest->decided_at?->toFormattedDateString(),
            'mintedBaselineVersion' => $changeRequest->mintedBaseline?->version,
            'createdByName' => $changeRequest->createdBy?->name,
            'createdAt' => $changeRequest->created_at?->toFormattedDateString(),
        ];
    }
}
