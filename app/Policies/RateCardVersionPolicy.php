<?php

namespace App\Policies;

use App\Models\User;

class RateCardVersionPolicy
{
    /**
     * Every managing role reads the rate card — baselines and change requests
     * are priced from it. Rates stay internal: members, portfolio viewers,
     * and the portal never see them.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->isManager();
    }

    /**
     * Publishing new rates is commercial territory: owner and commercial
     * manager only.
     */
    public function create(User $user): bool
    {
        return $user->role->managesRateCard();
    }
}
