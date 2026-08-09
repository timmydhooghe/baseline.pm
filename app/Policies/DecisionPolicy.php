<?php

namespace App\Policies;

use App\Enums\EngagementStatus;
use App\Models\Decision;
use App\Models\Engagement;
use App\Models\User;

class DecisionPolicy
{
    /**
     * The ledger answers "why is it like this?" for everyone who works on
     * the engagement, portfolio viewers included — reading history is what
     * it is for. Only records marked shared ever reach the portal, and that
     * happens through the frozen snapshot, never through this policy.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Decision $decision): bool
    {
        return $user->organization_id === $decision->organization_id;
    }

    /**
     * Recording decisions is engagement governance (FA-1), so it sits with
     * the managing roles. A completed or archived engagement's ledger is
     * history — corrections to it would be rewriting the past.
     */
    public function create(User $user, Engagement $engagement): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $engagement->organization_id
            && ! in_array($engagement->status, [EngagementStatus::Completed, EngagementStatus::Archived], true);
    }

    /**
     * Drafts stay editable; the model itself refuses edits once the record
     * is confirmed, so this only governs who may hold the pen.
     */
    public function update(User $user, Decision $decision): bool
    {
        return $this->create($user, $decision->engagement);
    }

    /**
     * Confirming puts the record on the ledger — the governance moment.
     */
    public function confirm(User $user, Decision $decision): bool
    {
        return $this->update($user, $decision);
    }

    /**
     * Only drafts can be discarded; the model enforces that. Everything
     * confirmed is superseded rather than deleted.
     */
    public function delete(User $user, Decision $decision): bool
    {
        return $decision->status->acceptsEdits() && $this->update($user, $decision);
    }
}
