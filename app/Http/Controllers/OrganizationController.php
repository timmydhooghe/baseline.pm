<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\RateCardVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    /**
     * Show the organization: plan usage, members, and (for the owner) the
     * pending invitations.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $organization = $user->organization;

        Gate::authorize('view', $organization);

        $managesMembers = $user->can('create', Invitation::class);

        return Inertia::render('organization/show', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'planLabel' => $organization->plan->label(),
            ],
            'planUsage' => [
                'activeCount' => $organization->activeEngagementCount(),
                'limit' => $organization->plan->activeEngagementLimit(),
            ],
            'members' => $organization->users()
                ->orderBy('name')
                ->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->role->value,
                    'roleLabel' => $member->role->label(),
                    'isCurrentUser' => $member->is($user),
                ]),
            'invitations' => $managesMembers
                ? $organization->invitations()
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (Invitation $invitation): array => [
                        'id' => $invitation->id,
                        'email' => $invitation->email,
                        'roleLabel' => $invitation->role->label(),
                        'expiresAt' => $invitation->expires_at->toFormattedDateString(),
                        'isExpired' => $invitation->isExpired(),
                    ])
                : [],
            'assignableRoles' => collect(UserRole::cases())
                ->reject(fn (UserRole $role): bool => $role === UserRole::Owner)
                ->map(fn (UserRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ])
                ->values(),
            'can' => [
                'manageMembers' => $managesMembers,
                'viewRateCard' => $user->can('viewAny', RateCardVersion::class),
            ],
        ]);
    }
}
