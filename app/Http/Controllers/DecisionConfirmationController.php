<?php

namespace App\Http\Controllers;

use App\Models\Decision;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Confirming a draft into the decision ledger (FA-18) — the governance
 * moment. From here the record is cited rather than edited: a change of mind
 * arrives as a new decision that supersedes this one.
 */
class DecisionConfirmationController extends Controller
{
    public function store(Request $request, Decision $decision): RedirectResponse
    {
        Gate::authorize('confirm', $decision);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $decision->confirm($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $decision->visibility->isShared()
            ? __('Decision confirmed and shared with the customer for acknowledgment.')
            : __('Decision confirmed and on the ledger.')]);

        return to_route('decisions.show', $decision);
    }
}
