<?php

namespace App\Http\Controllers;

use App\Enums\WorkItemTriageStatus;
use App\Http\Requests\Work\TriageWorkItemRequest;
use App\Models\BaselineItem;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WorkItemTriageController extends Controller
{
    /**
     * Record a triage decision on a scope creep item (FA-9): existing scope maps
     * it to the deliverable that absorbs the cost, potential change drafts a
     * pre-filled change request, operational excludes it with the logged
     * explanation, dismiss takes it off the queue — every decision recorded
     * with classifier and timestamp.
     */
    public function store(TriageWorkItemRequest $request, WorkItem $workItem): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $classification = WorkItemTriageStatus::from((string) $validated['classification']);

        $deliverable = null;

        if ($classification === WorkItemTriageStatus::ExistingScope) {
            $deliverable = BaselineItem::query()->find((string) $validated['baseline_item_id']);

            if ($deliverable === null) {
                throw ValidationException::withMessages([
                    'baseline_item_id' => __('That deliverable no longer exists.'),
                ]);
            }
        }

        $note = isset($validated['note']) && is_string($validated['note']) ? $validated['note'] : null;

        $workItem->triage($classification, $user, $deliverable, $note);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($classification) {
            WorkItemTriageStatus::ExistingScope => __('Recorded as existing scope and mapped to :deliverable — its cost is absorbed by margin.', [
                'deliverable' => $deliverable->title ?? '',
            ]),
            WorkItemTriageStatus::PotentialChange => __('Change request drafted from :item.', [
                'item' => $workItem->external_key ?? $workItem->title,
            ]),
            WorkItemTriageStatus::Operational => __('Excluded as operational — the explanation stays on record.'),
            WorkItemTriageStatus::Dismissed => __('Dismissed — the classification stays on record.'),
        }]);

        return to_route('engagements.triage.show', $workItem->engagement);
    }
}
