<?php

namespace App\Http\Controllers;

use App\Http\Requests\Work\LinkWorkItemsRequest;
use App\Models\BaselineItem;
use App\Models\Engagement;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WorkItemLinkController extends Controller
{
    /**
     * Map the selected work items to a deliverable in one go (FA-8).
     * Everything the mapping needs to be defensible — who, what, when — is
     * recorded per item.
     */
    public function store(LinkWorkItemsRequest $request, Engagement $engagement): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $deliverable = BaselineItem::query()->find((string) $validated['baseline_item_id']);

        if ($deliverable === null) {
            throw ValidationException::withMessages([
                'baseline_item_id' => __('That deliverable no longer exists.'),
            ]);
        }

        $workItems = $engagement->workItems()
            ->whereIn('id', $validated['work_item_ids'])
            ->with(['link', 'integration'])
            ->get();

        if ($workItems->count() !== count(array_unique($validated['work_item_ids']))) {
            throw ValidationException::withMessages([
                'work_item_ids' => __('Some selected work items do not belong to this engagement.'),
            ]);
        }

        DB::transaction(function () use ($workItems, $deliverable, $user): void {
            foreach ($workItems as $workItem) {
                $workItem->linkTo($deliverable, $user);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{1}Work item mapped to :deliverable.|[2,*]:count work items mapped to :deliverable.',
            $workItems->count(),
            ['count' => $workItems->count(), 'deliverable' => $deliverable->title],
        )]);

        return to_route('engagements.work.show', $engagement);
    }

    /**
     * Remove a work item's mapping; it becomes unmapped work again.
     */
    public function destroy(Request $request, WorkItem $workItem): RedirectResponse
    {
        Gate::authorize('link', $workItem);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $workItem->unlink($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mapping removed.')]);

        return to_route('engagements.work.show', $workItem->engagement);
    }
}
