<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    /**
     * Any member may see the member list of their organization.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $member): bool
    {
        return $user->organization_id === $member->organization_id;
    }

    /**
     * Only the owner manages members (invitation flow lands with WEBAPP-16).
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function update(User $user, User $member): bool
    {
        return $user->role === UserRole::Owner && $user->organization_id === $member->organization_id;
    }

    public function delete(User $user, User $member): bool
    {
        return $user->role === UserRole::Owner
            && $user->organization_id === $member->organization_id
            && $user->isNot($member);
    }
}
