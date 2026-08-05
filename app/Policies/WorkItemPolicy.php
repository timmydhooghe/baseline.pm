<?php

namespace App\Policies;

use App\Enums\EngagementStatus;
use App\Enums\WorkItemSource;
use App\Models\Engagement;
use App\Models\User;
use App\Models\WorkItem;

class WorkItemPolicy
{
    public function view(User $user, WorkItem $workItem): bool
    {
        return $user->organization_id === $workItem->organization_id;
    }

    /**
     * Recording a manual work item is authorized against its engagement.
     * Execution updates are every delivering role's job (FA-1); only
     * portfolio viewers stay read-only.
     */
    public function create(User $user, Engagement $engagement): bool
    {
        return $user->role->updatesExecution()
            && $user->organization_id === $engagement->organization_id
            && $engagement->status !== EngagementStatus::Archived;
    }

    /**
     * Only manual items are edited by hand — synced items mirror their
     * provider and change through sync runs.
     */
    public function update(User $user, WorkItem $workItem): bool
    {
        return $user->role->updatesExecution()
            && $user->organization_id === $workItem->organization_id
            && $workItem->source === WorkItemSource::Manual;
    }

    public function recordWorklog(User $user, WorkItem $workItem): bool
    {
        return $this->update($user, $workItem);
    }

    /**
     * Mapping work to deliverables in bulk, authorized against the
     * engagement (FA-8).
     */
    public function linkAny(User $user, Engagement $engagement): bool
    {
        return $user->role->updatesExecution()
            && $user->organization_id === $engagement->organization_id
            && $engagement->status !== EngagementStatus::Archived;
    }

    public function link(User $user, WorkItem $workItem): bool
    {
        return $user->role->updatesExecution()
            && $user->organization_id === $workItem->organization_id;
    }
}
