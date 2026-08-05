<?php

namespace App\Http\Controllers;

use App\Http\Requests\Engagements\SubmitFinalAcceptanceRequest;
use App\Models\Engagement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Submitting an engagement for final acceptance (FA-24): the gate before
 * Completed. Requires every deliverable to be signed off; the accepted
 * record freezes into twin snapshots and every customer approver is
 * notified with a personally signed review link.
 */
class FinalAcceptanceController extends Controller
{
    public function store(SubmitFinalAcceptanceRequest $request, Engagement $engagement): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $engagement->submitForFinalAcceptance((string) $request->validated()['respond_by'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Submitted for final acceptance — the customer approvers have been notified.')]);

        return to_route('engagements.show', $engagement);
    }
}
