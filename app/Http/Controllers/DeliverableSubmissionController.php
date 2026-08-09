<?php

namespace App\Http\Controllers;

use App\Http\Requests\Deliverables\SubmitDeliverableRequest;
use App\Models\Deliverable;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Submitting a deliverable for customer acceptance (FA-23): the record and
 * its evidence freeze into twin snapshots and every customer approver is
 * notified with a personally signed review link.
 */
class DeliverableSubmissionController extends Controller
{
    public function store(SubmitDeliverableRequest $request, Deliverable $deliverable): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $deliverable->submitForAcceptance((string) $request->validated()['respond_by'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Submitted for acceptance — the customer approvers have been notified.')]);

        return to_route('deliverables.show', $deliverable);
    }

    /**
     * Pull a submitted deliverable back before the customer decides, so a
     * premature submission — or one whose approvers are gone — reopens for
     * editing instead of waiting on a decision that cannot arrive.
     */
    public function destroy(Request $request, Deliverable $deliverable): RedirectResponse
    {
        Gate::authorize('submit', $deliverable);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $deliverable->withdrawSubmission($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Submission withdrawn — the record is open for editing again.')]);

        return to_route('deliverables.show', $deliverable);
    }
}
