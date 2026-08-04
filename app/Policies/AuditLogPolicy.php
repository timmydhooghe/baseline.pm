<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    /**
     * Managing roles may inspect the audit trail.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->isManager();
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->role->isManager() && $user->organization_id === $auditLog->organization_id;
    }

    /**
     * Audit logs are appended by the system, never managed by hand.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }
}
