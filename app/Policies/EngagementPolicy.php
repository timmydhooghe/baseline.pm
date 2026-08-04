<?php

namespace App\Policies;

use App\Enums\EngagementStatus;
use App\Models\Engagement;
use App\Models\User;

class EngagementPolicy
{
    /**
     * Every role sees the portfolio; members and portfolio viewers are
     * restricted to viewing — only managing roles change engagements.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Engagement $engagement): bool
    {
        return $user->organization_id === $engagement->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->role->isManager();
    }

    public function update(User $user, Engagement $engagement): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $engagement->organization_id
            && $engagement->status !== EngagementStatus::Archived;
    }

    /**
     * Lifecycle transitions (including archiving) follow the same rule as
     * updates, except that the move into Archived is itself allowed.
     */
    public function transition(User $user, Engagement $engagement): bool
    {
        return $user->role->isManager() && $user->organization_id === $engagement->organization_id;
    }

    /**
     * Engagements are never deleted — the lifecycle ends at Archived.
     */
    public function delete(User $user, Engagement $engagement): bool
    {
        return false;
    }
}
