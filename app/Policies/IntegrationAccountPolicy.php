<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\IntegrationAccount;
use App\Models\User;

class IntegrationAccountPolicy
{
    /**
     * Every managing role sees which accounts exist — engagements connect by
     * picking one. The credentials themselves never leave the backend.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->isManager();
    }

    public function view(User $user, IntegrationAccount $account): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $account->organization_id;
    }

    /**
     * Holding provider credentials for the whole organization is owner
     * territory — the one key is entered once, at the top.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function update(User $user, IntegrationAccount $account): bool
    {
        return $user->role === UserRole::Owner
            && $user->organization_id === $account->organization_id;
    }

    public function delete(User $user, IntegrationAccount $account): bool
    {
        return $user->role === UserRole::Owner
            && $user->organization_id === $account->organization_id;
    }
}
