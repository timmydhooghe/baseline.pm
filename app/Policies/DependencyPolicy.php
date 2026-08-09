<?php

namespace App\Policies;

use App\Enums\EngagementStatus;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\User;

class DependencyPolicy
{
    /**
     * Everyone working the engagement sees what it is waiting for.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Dependency $dependency): bool
    {
        return $user->organization_id === $dependency->organization_id;
    }

    /**
     * The register and its evidence trail are engagement governance (FA-1):
     * what gets chased, escalated and attributed is the delivery manager's
     * call, and it is the record a milestone slip is defended with.
     */
    public function create(User $user, Engagement $engagement): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $engagement->organization_id
            && ! in_array($engagement->status, [EngagementStatus::Completed, EngagementStatus::Archived], true);
    }

    public function update(User $user, Dependency $dependency): bool
    {
        return $this->create($user, $dependency->engagement);
    }

    /**
     * Items are received or waived, never deleted — the trail behind them is
     * what makes a delay attributable.
     */
    public function delete(User $user, Dependency $dependency): bool
    {
        return false;
    }
}
