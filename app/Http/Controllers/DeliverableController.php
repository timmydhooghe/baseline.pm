<?php

namespace App\Http\Controllers;

use App\Enums\BaselineItemType;
use App\Enums\DeliverableStatus;
use App\Enums\RecordVisibility;
use App\Http\Requests\Deliverables\UpdateDeliverableRequest;
use App\Models\BaselineItem;
use App\Models\Deliverable;
use App\Models\DeliverableEvidence;
use App\Models\DeliverableResponse;
use App\Models\DeliverableVersion;
use App\Models\Engagement;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DeliverableController extends Controller
{
    /**
     * The engagement's deliverable records (FA-22): execution and acceptance
     * state per contracted deliverable, grouped by milestone on the client.
     */
    public function index(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);

        $approved = $engagement->approvedBaseline();

        /*
         * Self-heal: baselines approved before acceptance records existed
         * (or seeded straight into Approved) provision their records here.
         */
        if ($approved !== null) {
            Deliverable::provisionForBaseline($approved);
        }

        $deliverables = $engagement->deliverables()
            ->with(['baselineItem.owner', 'milestoneItem', 'evidence'])
            ->get()
            ->sortBy(fn (Deliverable $deliverable): int => $deliverable->baselineItem->position)
            ->values();

        return Inertia::render('engagements/deliverables', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'baselineVersion' => $approved?->version,
            'deliverables' => $deliverables->map(function (Deliverable $deliverable): array {
                $criteria = $deliverable->criteria();

                return [
                    'id' => $deliverable->id,
                    'title' => $deliverable->baselineItem->title,
                    'ownerName' => $deliverable->baselineItem->owner?->name,
                    'value' => $deliverable->baselineItem->value?->toArray(),
                    'status' => $deliverable->status->value,
                    'statusLabel' => $deliverable->status->label(),
                    'progress' => $deliverable->progress,
                    'confidence' => $deliverable->confidence->value,
                    'confidenceLabel' => $deliverable->confidence->label(),
                    'forecastDate' => $deliverable->forecast_date?->toFormattedDateString(),
                    'milestoneItemId' => $deliverable->milestone_item_id,
                    'respondBy' => $deliverable->respond_by?->toFormattedDateString(),
                    'respondByOverdue' => $deliverable->status === DeliverableStatus::AwaitingAcceptance
                        && ($deliverable->respond_by?->isPast() ?? false),
                    'acceptedAt' => $deliverable->accepted_at?->toFormattedDateString(),
                    'acceptedValue' => $deliverable->accepted_value?->toArray(),
                    'criteriaCount' => count($criteria),
                    'evidencedCriteriaCount' => count(array_filter($criteria, fn (array $criterion): bool => $criterion['evidence'] !== null)),
                    'evidenceCount' => $deliverable->evidence->count(),
                ];
            }),
            'milestones' => $approved === null ? [] : $approved->items
                ->where('type', BaselineItemType::Milestone)
                ->map(fn (BaselineItem $item): array => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'baselineDate' => $item->baseline_date?->toFormattedDateString(),
                    'paymentTrigger' => $item->payment_trigger,
                ])
                ->values(),
            'accepted' => [
                'count' => $deliverables->where('status', DeliverableStatus::Accepted)->count(),
                'total' => $deliverables->count(),
                'value' => $engagement->acceptedValue()->toArray(),
            ],
            'position' => $engagement->positionSummary(),
        ]);
    }

    /**
     * The full deliverable record (FA-22): baseline reference and version
     * history, owner, milestone, value, progress, confidence, forecast,
     * evidence-backed acceptance criteria, evidence list, linked work with
     * scope classification, and the acceptance flow's state.
     */
    public function show(Request $request, Deliverable $deliverable): Response
    {
        Gate::authorize('view', $deliverable);

        $deliverable->load([
            'baselineItem.baseline',
            'baselineItem.owner',
            'milestoneItem',
            'evidence.addedBy',
            'responses',
            'versions.baselineItem.baseline',
        ]);

        $engagement = $deliverable->engagement;
        $item = $deliverable->baselineItem;
        $user = $request->user();

        return Inertia::render('deliverables/show', [
            'deliverable' => [
                'id' => $deliverable->id,
                'title' => $item->title,
                'description' => $item->description,
                'clauseReference' => $item->clause_reference,
                'baselineVersion' => $item->baseline->version,
                'ownerName' => $item->owner?->name,
                'value' => $item->value?->toArray(),
                'status' => $deliverable->status->value,
                'statusLabel' => $deliverable->status->label(),
                'progress' => $deliverable->progress,
                'confidence' => $deliverable->confidence->value,
                'forecastDate' => $deliverable->forecast_date?->toDateString(),
                'milestoneItemId' => $deliverable->milestone_item_id,
                'submittedAt' => $deliverable->submitted_at?->toFormattedDateString(),
                'respondBy' => $deliverable->respond_by?->toFormattedDateString(),
                'respondByOverdue' => $deliverable->status === DeliverableStatus::AwaitingAcceptance
                    && ($deliverable->respond_by?->isPast() ?? false),
                'decidedAt' => $deliverable->decided_at?->toFormattedDateString(),
                'acceptedAt' => $deliverable->accepted_at?->toFormattedDateString(),
                'acceptedValue' => $deliverable->accepted_value?->toArray(),
            ],
            'criteria' => collect($deliverable->criteria())
                ->map(fn (array $criterion): array => [
                    'criterion' => $criterion['criterion'],
                    'verificationMethod' => $criterion['verification_method'],
                    'evidenceId' => $criterion['evidence']?->id,
                    'visibility' => $criterion['visibility']->value,
                ])
                ->values(),
            'evidence' => $deliverable->evidence
                ->map(fn (DeliverableEvidence $evidence): array => [
                    'id' => $evidence->id,
                    'kind' => $evidence->kind->value,
                    'kindLabel' => $evidence->kind->label(),
                    'label' => $evidence->label,
                    'url' => $evidence->url,
                    'visibility' => $evidence->visibility->value,
                    'visibilityLabel' => $evidence->visibility->label(),
                    'addedByName' => $evidence->addedBy?->name,
                    'addedAt' => $evidence->created_at?->toFormattedDateString(),
                ])
                ->values(),
            'versions' => $deliverable->versions
                ->map(fn (DeliverableVersion $version): array => [
                    'id' => $version->id,
                    'baselineVersion' => $version->baselineItem->baseline->version,
                    'value' => $version->baselineItem->value?->toArray(),
                    'recordedAt' => $version->created_at->toFormattedDateString(),
                ])
                ->values(),
            'linkedWork' => $deliverable->linkedWork()
                ->map(fn (WorkItem $workItem): array => [
                    'id' => $workItem->id,
                    'title' => $workItem->title,
                    'externalKey' => $workItem->external_key,
                    'externalUrl' => $workItem->external_url,
                    'stateLabel' => $workItem->state->label(),
                    'classification' => $workItem->triage_status?->value,
                    'classificationLabel' => $workItem->triage_status?->label(),
                ])
                ->values(),
            'responses' => $deliverable->responses
                ->map(fn (DeliverableResponse $response): array => [
                    'id' => $response->id,
                    'decision' => $response->decision->value,
                    'decisionLabel' => $response->decision->label(),
                    'stakeholderName' => $response->stakeholder_name,
                    'comment' => $response->comment,
                    'respondedAt' => $response->created_at->toFormattedDateString(),
                ])
                ->values(),
            'milestoneOptions' => $item->baseline->items
                ->where('type', BaselineItemType::Milestone)
                ->map(fn (BaselineItem $milestone): array => [
                    'id' => $milestone->id,
                    'title' => $milestone->title,
                    'baselineDate' => $milestone->baseline_date?->toFormattedDateString(),
                ])
                ->values(),
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
            ],
            'position' => $engagement->positionSummary(),
            'can' => [
                'update' => ($user instanceof User && $user->can('update', $deliverable))
                    && $deliverable->status->acceptsUpdates(),
                'submit' => ($user instanceof User && $user->can('submit', $deliverable))
                    && $deliverable->status->canTransitionTo(DeliverableStatus::AwaitingAcceptance),
            ],
        ]);
    }

    /**
     * Apply an execution update (FA-22): progress, confidence, forecast,
     * milestone assignment and per-criterion evidence links. The contractual
     * definition never changes here — that is the baseline's job.
     */
    public function update(UpdateDeliverableRequest $request, Deliverable $deliverable): RedirectResponse
    {
        $validated = $request->validated();

        $criteriaInput = array_values($validated['criteria'] ?? []);
        $existing = $deliverable->criteria_state ?? [];
        $state = [];

        foreach (array_keys($deliverable->baselineItem->acceptance_criteria ?? []) as $index) {
            $entry = $criteriaInput[$index] ?? $existing[$index] ?? [];

            $state[] = [
                'evidence_id' => $entry['evidence_id'] ?? null,
                'visibility' => (RecordVisibility::tryFrom($entry['visibility'] ?? '') ?? RecordVisibility::Shared)->value,
            ];
        }

        $deliverable->update([
            'progress' => $validated['progress'],
            'confidence' => $validated['confidence'],
            'forecast_date' => $validated['forecast_date'] ?? null,
            'milestone_item_id' => $validated['milestone_item_id'] ?? null,
            'criteria_state' => $state,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deliverable record updated.')]);

        return to_route('deliverables.show', $deliverable);
    }
}
