<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A structured exposure line on a risk (FA-19): effort at risk as days for
 * one rate card role. The euro figure derives from the role's cost rate on
 * the version pinned to the risk — the register never accepts a typed
 * amount, so every exposure traces back to a published rate card.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $risk_id
 * @property string $rate_card_role_id
 * @property string $days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Risk $risk
 * @property-read RateCardRole $role
 */
#[Fillable(['organization_id', 'rate_card_role_id', 'days'])]
class RiskExposure extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * The euro exposure of this line: days at risk times the pinned cost
     * rate, rounded to whole cents.
     */
    public function cost(): Money
    {
        return Money::fromCents((int) round((float) $this->days * $this->role->cost_per_day->amount));
    }

    /**
     * @return BelongsTo<Risk, $this>
     */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    /**
     * @return BelongsTo<RateCardRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(RateCardRole::class, 'rate_card_role_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'decimal:2',
        ];
    }
}
