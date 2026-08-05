<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeRequests\UpdateChangeRequestAssessmentRequest;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestAllocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ChangeRequestAssessmentController extends Controller
{
    /**
     * Replace the structured assessment of a change request (FA-12): the
     * role mix priced at the pinned rate card version, the affected baseline
     * items, the structured schedule impact and the scope narrative. The
     * whole set is written at once under a row lock, so a submission cannot
     * slip in between delete and insert and freeze a half-written mix.
     */
    public function update(UpdateChangeRequestAssessmentRequest $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($changeRequest, $validated): void {
            ChangeRequest::query()->whereKey($changeRequest->id)->lockForUpdate()->first();

            $changeRequest->unsetRelations();
            $changeRequest->refresh();

            if (! $changeRequest->status->acceptsAssessment()) {
                throw ValidationException::withMessages([
                    'assessment' => __('The assessment left editing while you were working — reload to see its current state.'),
                ]);
            }

            $changeRequest->allocations->each(fn (ChangeRequestAllocation $allocation) => $allocation->delete());

            /** @var list<array{rate_card_role_id: string, days: string|int|float}> $allocations */
            $allocations = $validated['allocations'] ?? [];

            foreach ($allocations as $allocation) {
                $changeRequest->allocations()->create([
                    'organization_id' => $changeRequest->organization_id,
                    'rate_card_role_id' => $allocation['rate_card_role_id'],
                    'days' => (string) $allocation['days'],
                ]);
            }

            /** @var list<string> $affectedItems */
            $affectedItems = $validated['affected_items'] ?? [];
            $changeRequest->affectedItems()->sync($affectedItems);

            $changeRequest->update([
                'impact_milestone_id' => $validated['impact_milestone_id'] ?? null,
                'impact_days' => $validated['impact_days'] ?? null,
                'scope_added' => $validated['scope_added'] ?? null,
                'scope_removed' => $validated['scope_removed'] ?? null,
                'alternatives' => $validated['alternatives'] ?? null,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Assessment saved — commercial terms derive from the pinned version.')]);

        return to_route('change-requests.show', $changeRequest);
    }
}
