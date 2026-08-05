<?php

namespace App\Policies;

use App\Enums\BaselineStatus;
use App\Enums\EngagementStatus;
use App\Models\Baseline;
use App\Models\Engagement;
use App\Models\User;

class BaselinePolicy
{
    /**
     * Baselines (cost budget and margin included) are internal to the
     * organization; the portal only ever sees the customer snapshot.
     */
    public function view(User $user, Baseline $baseline): bool
    {
        return $user->organization_id === $baseline->organization_id;
    }

    /**
     * Drafting a baseline is authorized against the engagement it is for.
     */
    public function create(User $user, Engagement $engagement): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $engagement->organization_id
            && $engagement->status !== EngagementStatus::Archived;
    }

    /**
     * Only draft baselines change — a submitted one is frozen for review
     * and an approved one is immutable forever.
     */
    public function update(User $user, Baseline $baseline): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $baseline->organization_id
            && $baseline->status === BaselineStatus::Draft;
    }

    public function submit(User $user, Baseline $baseline): bool
    {
        return $this->update($user, $baseline);
    }

    /**
     * Baselines are never deleted — they are the commitment history.
     */
    public function delete(User $user, Baseline $baseline): bool
    {
        return false;
    }
}
