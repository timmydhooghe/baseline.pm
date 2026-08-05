<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeRequests\SubmitChangeRequestRequest;
use App\Models\ChangeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ChangeRequestSubmissionController extends Controller
{
    /**
     * Submit the proposal for customer approval (FA-13): freeze the twin
     * snapshots, stamp the respond-by deadline and notify every stakeholder
     * with approval rights through their personal signed link.
     */
    public function store(SubmitChangeRequestRequest $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $changeRequest->submitToCustomer((string) $request->validated()['respond_by'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proposal submitted — the customer approvers have been notified.')]);

        return to_route('change-requests.show', $changeRequest);
    }
}
