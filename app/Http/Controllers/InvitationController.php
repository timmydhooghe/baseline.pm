<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\InviteMemberRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\MemberInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;

class InvitationController extends Controller
{
    /**
     * Invite an internal member by email. External stakeholders never go
     * through this flow — they live on customer records and are free.
     */
    public function store(InviteMemberRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $validated = $request->validated();

        $invitation = new Invitation([
            'email' => $validated['email'],
            'role' => $validated['role'],
            'token' => Str::random(64),
            'expires_at' => now()->addDays(Invitation::EXPIRES_AFTER_DAYS),
        ]);
        $invitation->inviter()->associate($user);
        $invitation->save();

        Notification::route('mail', $invitation->email)->notify(new MemberInvitation($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', [
            'email' => $invitation->email,
        ])]);

        return to_route('organization.show');
    }

    /**
     * Revoke a pending invitation.
     */
    public function destroy(Invitation $invitation): RedirectResponse
    {
        Gate::authorize('delete', $invitation);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation for :email revoked.', [
            'email' => $invitation->email,
        ])]);

        return to_route('organization.show');
    }
}
