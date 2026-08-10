<?php

namespace App\Policies;

use App\Enums\EngagementStatus;
use App\Models\BurnWeek;
use App\Models\Engagement;
use App\Models\User;

/**
 * Burn is cost, and cost is internal (FA-27). Unlike the risk register —
 * where the risk is everyone's business and only its price is not — a burn
 * week carries nothing but money, so the whole record stays with the roles
 * that may read the rate card.
 */
class BurnWeekPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isManager();
    }

    public function view(User $user, BurnWeek $burnWeek): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $burnWeek->organization_id;
    }

    /**
     * Recording a week is engagement governance and stays with the managing
     * roles; a completed or archived engagement has stopped burning.
     */
    public function create(User $user, Engagement $engagement): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $engagement->organization_id
            && ! in_array($engagement->status, [EngagementStatus::Completed, EngagementStatus::Archived], true);
    }

    /**
     * Recorded weeks are immutable — a correction is a new recording, which
     * is a create.
     */
    public function update(User $user, BurnWeek $burnWeek): bool
    {
        return false;
    }

    public function delete(User $user, BurnWeek $burnWeek): bool
    {
        return false;
    }
}
