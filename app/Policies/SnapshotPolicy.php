<?php

namespace App\Policies;

use App\Models\Snapshot;
use App\Models\User;

class SnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Snapshot $snapshot): bool
    {
        return $user->organization_id === $snapshot->organization_id;
    }

    /**
     * Managing roles freeze snapshots (baselines, CRs, reports, burn weeks).
     */
    public function create(User $user): bool
    {
        return $user->role->isManager();
    }

    /**
     * Snapshots are immutable once captured.
     */
    public function update(User $user, Snapshot $snapshot): bool
    {
        return false;
    }

    public function delete(User $user, Snapshot $snapshot): bool
    {
        return false;
    }
}
