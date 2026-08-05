<?php

namespace App\Policies;

use App\Enums\ChangeRequestStatus;
use App\Enums\EngagementStatus;
use App\Models\ChangeRequest;
use App\Models\Engagement;
use App\Models\User;

class ChangeRequestPolicy
{
    /**
     * Change requests (derived cost and margin included) are internal to
     * the organization; the portal only ever sees the customer snapshot.
     */
    public function view(User $user, ChangeRequest $changeRequest): bool
    {
        return $user->organization_id === $changeRequest->organization_id;
    }

    /**
     * Raising a change request is authorized against the engagement it
     * changes. Owners, delivery managers and commercial managers govern
     * change control (FA-1).
     */
    public function create(User $user, Engagement $engagement): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $engagement->organization_id
            && $engagement->status !== EngagementStatus::Archived;
    }

    /**
     * A change request only changes while it is open on the internal side:
     * submitted proposals are frozen for the customer and decisions are
     * immutable. Lifecycle moves (assessment, proposal, submission) are
     * edits of the same record, so they share this gate.
     */
    public function update(User $user, ChangeRequest $changeRequest): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $changeRequest->organization_id
            && $changeRequest->engagement->status !== EngagementStatus::Archived
            && ! $changeRequest->status->isDecided()
            && $changeRequest->status !== ChangeRequestStatus::AwaitingApproval;
    }

    /**
     * Change requests are never deleted — even drafts document that a
     * change was considered.
     */
    public function delete(User $user, ChangeRequest $changeRequest): bool
    {
        return false;
    }
}
