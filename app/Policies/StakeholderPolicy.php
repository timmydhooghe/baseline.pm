<?php

namespace App\Policies;

use App\Models\Stakeholder;
use App\Models\User;

class StakeholderPolicy
{
    /**
     * Managing roles handle customer-side contacts (full flow lands with WEBAPP-16).
     */
    public function viewAny(User $user): bool
    {
        return $user->role->isManager();
    }

    public function view(User $user, Stakeholder $stakeholder): bool
    {
        return $user->role->isManager() && $user->organization_id === $stakeholder->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->role->isManager();
    }

    public function update(User $user, Stakeholder $stakeholder): bool
    {
        return $user->role->isManager() && $user->organization_id === $stakeholder->organization_id;
    }

    public function delete(User $user, Stakeholder $stakeholder): bool
    {
        return $user->role->isManager() && $user->organization_id === $stakeholder->organization_id;
    }
}
