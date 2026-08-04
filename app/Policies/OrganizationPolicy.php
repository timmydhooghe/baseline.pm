<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Any member may view their own organization.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $user->organization_id === $organization->id;
    }

    /**
     * Only the owner may change organization settings.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->role === UserRole::Owner && $user->organization_id === $organization->id;
    }
}
