<?php

namespace App\Policies;

use App\Enums\EngagementStatus;
use App\Enums\IntegrationConnectionStatus;
use App\Models\Engagement;
use App\Models\IntegrationConnection;
use App\Models\User;

class IntegrationConnectionPolicy
{
    public function view(User $user, IntegrationConnection $connection): bool
    {
        return $user->organization_id === $connection->organization_id;
    }

    /**
     * Connecting (and reconnecting) is authorized against the engagement
     * the integration serves. Wiring credentials into an execution tool is
     * a governance decision, so it stays with managing roles.
     */
    public function create(User $user, Engagement $engagement): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $engagement->organization_id
            && $engagement->status !== EngagementStatus::Archived;
    }

    public function disconnect(User $user, IntegrationConnection $connection): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $connection->organization_id
            && $connection->status === IntegrationConnectionStatus::Connected;
    }

    public function sync(User $user, IntegrationConnection $connection): bool
    {
        return $user->role->isManager()
            && $user->organization_id === $connection->organization_id
            && $connection->status === IntegrationConnectionStatus::Connected;
    }

    /**
     * Connections are never deleted — disconnecting retains the imported
     * history (FA-7).
     */
    public function delete(User $user, IntegrationConnection $connection): bool
    {
        return false;
    }
}
