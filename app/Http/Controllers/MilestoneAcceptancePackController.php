<?php

namespace App\Http\Controllers;

use App\Enums\AcceptanceDecision;
use App\Enums\BaselineItemType;
use App\Enums\DeliverableStatus;
use App\Models\BaselineItem;
use App\Models\Deliverable;
use App\Models\DeliverableEvidence;
use App\Models\DeliverableResponse;
use App\Models\Engagement;
use App\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A milestone's acceptance pack (FA-23): the assembly of its deliverables'
 * signed acceptances — who signed what, when, at what value, on what
 * evidence. The pack is complete when every assigned deliverable carries a
 * signature; it is a derived view, the signatures themselves live on the
 * immutable responses and snapshots.
 */
class MilestoneAcceptancePackController extends Controller
{
    public function show(Request $request, Engagement $engagement, BaselineItem $milestone): Response
    {
        Gate::authorize('view', $engagement);

        $current = $engagement->approvedBaseline();

        abort_unless(
            $current !== null
            && $milestone->baseline_id === $current->id
            && $milestone->type === BaselineItemType::Milestone,
            404,
        );

        $deliverables = $engagement->deliverables()
            ->where('milestone_item_id', $milestone->id)
            ->with(['baselineItem.owner', 'evidence', 'responses'])
            ->get()
            ->sortBy(fn (Deliverable $deliverable): int => $deliverable->baselineItem->position)
            ->values();

        $accepted = $deliverables->where('status', DeliverableStatus::Accepted);

        return Inertia::render('milestones/acceptance-pack', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
            ],
            'milestone' => [
                'id' => $milestone->id,
                'title' => $milestone->title,
                'baselineDate' => $milestone->baseline_date?->toFormattedDateString(),
                'paymentTrigger' => $milestone->payment_trigger,
                'clauseReference' => $milestone->clause_reference,
            ],
            'deliverables' => $deliverables->map(function (Deliverable $deliverable): array {
                $acceptance = $deliverable->responses
                    ->first(fn (DeliverableResponse $response): bool => $response->decision === AcceptanceDecision::Accepted);

                return [
                    'id' => $deliverable->id,
                    'title' => $deliverable->baselineItem->title,
                    'clauseReference' => $deliverable->baselineItem->clause_reference,
                    'ownerName' => $deliverable->baselineItem->owner?->name,
                    'value' => $deliverable->baselineItem->value?->toArray(),
                    'status' => $deliverable->status->value,
                    'statusLabel' => $deliverable->status->label(),
                    'acceptedAt' => $deliverable->accepted_at?->toFormattedDateString(),
                    'acceptedValue' => $deliverable->accepted_value?->toArray(),
                    'acceptedBy' => $acceptance?->stakeholder_name,
                    'acceptanceComment' => $acceptance?->comment,
                    'evidence' => $deliverable->evidence
                        ->map(fn (DeliverableEvidence $evidence): array => [
                            'id' => $evidence->id,
                            'kindLabel' => $evidence->kind->label(),
                            'label' => $evidence->label,
                            'url' => $evidence->url,
                            'visibility' => $evidence->visibility->value,
                        ])
                        ->values()
                        ->all(),
                ];
            }),
            'totals' => [
                'count' => $deliverables->count(),
                'acceptedCount' => $accepted->count(),
                'value' => $deliverables
                    ->reduce(fn (Money $sum, Deliverable $deliverable): Money => $sum->add($deliverable->baselineItem->value ?? Money::zero()), Money::zero())
                    ->toArray(),
                'acceptedValue' => $accepted
                    ->reduce(fn (Money $sum, Deliverable $deliverable): Money => $sum->add($deliverable->accepted_value ?? Money::zero()), Money::zero())
                    ->toArray(),
            ],
            'complete' => $deliverables->isNotEmpty() && $accepted->count() === $deliverables->count(),
            'position' => $engagement->positionSummary(),
        ]);
    }
}
