<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    /**
     * Managing roles handle customer records and their stakeholders.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->isManager();
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->role->isManager() && $user->organization_id === $customer->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->role->isManager();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->role->isManager() && $user->organization_id === $customer->organization_id;
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->role->isManager() && $user->organization_id === $customer->organization_id;
    }
}
