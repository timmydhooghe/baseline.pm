<?php

namespace App\Policies;

use App\Enums\EngagementStatus;
use App\Models\Deliverable;
use App\Models\User;

class DeliverablePolicy
{
    /**
     * Deliverable records (confidence and internal evidence included) are
     * internal to the organization; the portal only ever sees the customer
     * snapshot.
     */
    public function view(User $user, Deliverable $deliverable): bool
    {
        return $user->organization_id === $deliverable->organization_id;
    }

    /**
     * Execution updates — progress, confidence, forecast, milestone
     * assignment, evidence — are made by everyone who delivers (FA-1):
     * members included, only portfolio viewers stay read-only. The record
     * itself refuses edits while frozen or signed; completed and archived
     * engagements are history.
     */
    public function update(User $user, Deliverable $deliverable): bool
    {
        return $user->role->updatesExecution()
            && $user->organization_id === $deliverable->organization_id
            && ! in_array($deliverable->engagement->status, [EngagementStatus::Completed, EngagementStatus::Archived], true);
    }

    /**
     * Submitting for acceptance is governance — it freezes the record and
     * asks the customer for a signature, so it stays with managing roles.
     */
    public function submit(User $user, Deliverable $deliverable): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $deliverable->organization_id
            && ! in_array($deliverable->engagement->status, [EngagementStatus::Completed, EngagementStatus::Archived], true);
    }

    /**
     * Deliverable records are never deleted — they are the acceptance ledger.
     */
    public function delete(User $user, Deliverable $deliverable): bool
    {
        return false;
    }
}
