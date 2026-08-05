<?php

namespace App\Http\Controllers;

use App\Http\Requests\Baselines\UpdateBaselineCommercialsRequest;
use App\Models\Baseline;
use App\Models\BaselineAllocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BaselineCommercialController extends Controller
{
    /**
     * Replace the draft baseline's role mix (wizard step 4). The whole set
     * is written at once, like a rate card version: per-deliverable lines
     * plus delivery-management lines without an item. Cost stays derived
     * from the pinned rate card version.
     */
    public function update(UpdateBaselineCommercialsRequest $request, Baseline $baseline): RedirectResponse
    {
        /** @var list<array{baseline_item_id?: string|null, rate_card_role_id: string, days: string|int|float}> $allocations */
        $allocations = $request->validated('allocations');

        DB::transaction(function () use ($baseline, $allocations): void {
            $baseline->allocations->each(fn (BaselineAllocation $allocation) => $allocation->delete());

            foreach ($allocations as $allocation) {
                $baseline->allocations()->create([
                    'organization_id' => $baseline->organization_id,
                    'baseline_item_id' => $allocation['baseline_item_id'] ?? null,
                    'rate_card_role_id' => $allocation['rate_card_role_id'],
                    'days' => (string) $allocation['days'],
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role mix and cost budget saved.')]);

        return to_route('engagements.baseline.show', $baseline->engagement_id);
    }
}
