<?php

namespace App\Http\Controllers;

use App\Http\Requests\Deliverables\StoreDeliverableEvidenceRequest;
use App\Models\AuditLog;
use App\Models\Deliverable;
use App\Models\DeliverableEvidence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The evidence list on a deliverable record (FA-22). Additions and removals
 * are governance moments and land on the audit log; once the record is
 * frozen for review or signed, the list stops moving.
 */
class DeliverableEvidenceController extends Controller
{
    public function store(StoreDeliverableEvidenceRequest $request, Deliverable $deliverable): RedirectResponse
    {
        $validated = $request->validated();

        $evidence = $deliverable->evidence()->create([
            'organization_id' => $deliverable->organization_id,
            'kind' => $validated['kind'],
            'label' => $validated['label'],
            'url' => $validated['url'] ?? null,
            'visibility' => $validated['visibility'],
            'added_by' => $request->user()?->id,
        ]);

        AuditLog::record('deliverable.evidence_added', $deliverable, [
            'deliverable' => $deliverable->baselineItem->title,
            'kind' => $evidence->kind->value,
            'label' => $evidence->label,
            'visibility' => $evidence->visibility->value,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Evidence :label added.', ['label' => $evidence->label])]);

        return to_route('deliverables.show', $deliverable);
    }

    public function destroy(Request $request, Deliverable $deliverable, DeliverableEvidence $evidence): RedirectResponse
    {
        Gate::authorize('update', $deliverable);

        if (! $deliverable->status->acceptsUpdates()) {
            throw ValidationException::withMessages([
                'label' => __('This record is frozen — it awaits the customer decision or carries a signed acceptance.'),
            ]);
        }

        DB::transaction(function () use ($deliverable, $evidence): void {
            /*
             * Criteria pointing at the removed item fall back to unevidenced
             * rather than dangling — the submission gate will catch them.
             */
            $state = array_map(
                fn (array $entry): array => [
                    'evidence_id' => $entry['evidence_id'] === $evidence->id ? null : $entry['evidence_id'],
                    'visibility' => $entry['visibility'],
                ],
                $deliverable->criteria_state ?? [],
            );

            $deliverable->update(['criteria_state' => $state]);
            $evidence->delete();

            AuditLog::record('deliverable.evidence_removed', $deliverable, [
                'deliverable' => $deliverable->baselineItem->title,
                'kind' => $evidence->kind->value,
                'label' => $evidence->label,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Evidence :label removed.', ['label' => $evidence->label])]);

        return to_route('deliverables.show', $deliverable);
    }
}
