<?php

namespace App\Http\Controllers;

use App\Http\Requests\Work\StoreWorkItemWorklogRequest;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class WorkItemWorklogController extends Controller
{
    /**
     * Log time by hand on a manual work item (FA-7 standalone mode). Synced
     * items get their worklogs from the provider.
     */
    public function store(StoreWorkItemWorklogRequest $request, WorkItem $workItem): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $workItem->addManualWorklog(
            (float) $validated['hours'],
            $validated['logged_on'],
            $user,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Time logged.')]);

        return to_route('engagements.work.show', $workItem->engagement);
    }
}
