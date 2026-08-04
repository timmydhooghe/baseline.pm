<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    /**
     * Show the acceptance page for an invitation token.
     */
    public function show(string $token): Response
    {
        $invitation = $this->findByToken($token);

        return Inertia::render('auth/accept-invitation', [
            'invitation' => [
                'token' => $invitation->token,
                'email' => $invitation->email,
                'roleLabel' => $invitation->role->label(),
                'organizationName' => $invitation->organization->name,
                'inviterName' => $invitation->inviter->name ?? null,
                'isExpired' => $invitation->isExpired(),
            ],
        ]);
    }

    /**
     * Accept the invitation: create the member and sign them in. Following
     * the emailed token proves control of the mailbox, so the address is
     * marked verified immediately.
     */
    public function store(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->findByToken($token);

        if ($invitation->isExpired()) {
            return to_route('invitations.show', ['token' => $invitation->token]);
        }

        if (User::query()->where('email', $invitation->email)->exists()) {
            throw ValidationException::withMessages([
                'name' => __('This email already has a Baseline account. Ask for a new invitation on a different address.'),
            ]);
        }

        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated, $invitation): User {
            $user = new User([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => $validated['password'],
            ]);
            $user->organization()->associate($invitation->organization);
            $user->role = $invitation->role;
            $user->email_verified_at = Carbon::now();
            $user->save();

            $invitation->delete();

            AuditLog::record('member.joined', $user, ['role' => $user->role->value], $user);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }

    /**
     * Invitation links are token-authenticated and organization-agnostic —
     * the visitor holds no session for the inviting organization, so the
     * tenant scope deliberately must not apply.
     */
    private function findByToken(string $token): Invitation
    {
        return Invitation::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('token', $token)
            ->firstOrFail();
    }
}
