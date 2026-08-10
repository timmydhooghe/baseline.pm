<?php

namespace App\Policies;

use App\Enums\EngagementStatus;
use App\Models\Engagement;
use App\Models\Report;
use App\Models\User;

/**
 * Weekly reports are the engagement's story and everyone inside the
 * organization may read it — like the risk register, the record is shared and
 * only its commercial figures are not. The controller serves the internal
 * payload without its commercials block to roles that may not read the rate
 * card, so the gate here is membership, not money.
 */
class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Report $report): bool
    {
        return $user->organization_id === $report->organization_id;
    }

    /**
     * Publishing is engagement governance and stays with the managing roles;
     * a completed or archived engagement has stopped reporting.
     */
    public function create(User $user, Engagement $engagement): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $engagement->organization_id
            && ! in_array($engagement->status, [EngagementStatus::Completed, EngagementStatus::Archived], true);
    }

    /**
     * Published reports are immutable — what was sent is what stays sent.
     */
    public function update(User $user, Report $report): bool
    {
        return false;
    }

    public function delete(User $user, Report $report): bool
    {
        return false;
    }
}
