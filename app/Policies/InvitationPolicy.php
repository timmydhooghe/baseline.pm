<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\User;

class InvitationPolicy
{
    /**
     * Only the owner manages members, so only the owner sees and manages
     * pending invitations.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function view(User $user, Invitation $invitation): bool
    {
        return $user->role === UserRole::Owner && $user->organization_id === $invitation->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    /**
     * Invitations are revoked and re-sent, never edited.
     */
    public function update(User $user, Invitation $invitation): bool
    {
        return false;
    }

    public function delete(User $user, Invitation $invitation): bool
    {
        return $user->role === UserRole::Owner && $user->organization_id === $invitation->organization_id;
    }
}
