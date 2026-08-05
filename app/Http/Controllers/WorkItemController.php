<?php

namespace App\Http\Controllers;

use App\Enums\EstimateUnit;
use App\Http\Requests\Work\StoreWorkItemRequest;
use App\Http\Requests\Work\UpdateWorkItemRequest;
use App\Models\AuditLog;
use App\Models\Engagement;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class WorkItemController extends Controller
{
    /**
     * Record a manual work item — standalone-mode execution (FA-4, FA-7).
     */
    public function store(StoreWorkItemRequest $request, Engagement $engagement): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $engagement->addManualWorkItem(
            $this->attributes($request->validated()),
            $user,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Work item recorded.')]);

        return to_route('engagements.work.show', $engagement);
    }

    /**
     * Update a manual work item. Synced items mirror their provider — the
     * request's policy check refuses them.
     */
    public function update(UpdateWorkItemRequest $request, WorkItem $workItem): RedirectResponse
    {
        $workItem->fill($this->attributes($request->validated()));
        $changes = $workItem->getDirty();
        $workItem->save();

        if ($changes !== []) {
            AuditLog::record('work_item.updated', $workItem, ['changes' => $changes]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Work item updated.')]);

        return to_route('engagements.work.show', $workItem->engagement);
    }

    /**
     * Map validated input onto work item attributes: manual estimates are
     * entered in days and stored with their unit.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $attributes = collect($validated)->except('estimate_days')->all();

        if (array_key_exists('estimate_days', $validated)) {
            $attributes['estimate_value'] = $validated['estimate_days'];
            $attributes['estimate_unit'] = $validated['estimate_days'] === null ? null : EstimateUnit::Days;
        }

        return $attributes;
    }
}
