<?php

namespace App\Policies;

use App\Enums\EngagementStatus;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\User;

class RiskPolicy
{
    /**
     * The register is read by everyone who works on the engagement. Its
     * exposure figures are cost-derived, so pages strip them for viewers
     * without rate card access — visibility of the risk itself is not the
     * same question as visibility of what it would cost.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Risk $risk): bool
    {
        return $user->organization_id === $risk->organization_id;
    }

    /**
     * Carrying risks is engagement governance (FA-1) and stays with the
     * managing roles; a completed or archived engagement's register is
     * closed.
     */
    public function create(User $user, Engagement $engagement): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $engagement->organization_id
            && ! in_array($engagement->status, [EngagementStatus::Completed, EngagementStatus::Archived], true);
    }

    public function update(User $user, Risk $risk): bool
    {
        return $this->create($user, $risk->engagement);
    }

    /**
     * Risks are closed, never deleted — the register is history.
     */
    public function delete(User $user, Risk $risk): bool
    {
        return false;
    }
}
