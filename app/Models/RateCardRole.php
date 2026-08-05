<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use App\ValueObjects\Money;
use Database\Factories\RateCardRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A staffing role line on a rate card version: cost/day and sell/day for
 * e.g. "Senior developer". Immutable once published — cost and margin
 * figures derive from these rates via the pinned version, never from
 * free-typed amounts.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $rate_card_version_id
 * @property string $name
 * @property Money $cost_per_day
 * @property Money $sell_per_day
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read RateCardVersion $version
 */
#[Fillable(['organization_id', 'name', 'cost_per_day', 'sell_per_day', 'position'])]
class RateCardRole extends Model
{
    /** @use HasFactory<RateCardRoleFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, RecordsAuditLog;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Rate card roles are immutable; publish a new rate card version instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('Rate card roles are immutable; publish a new rate card version instead.');
        });
    }

    /**
     * @return BelongsTo<RateCardVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(RateCardVersion::class, 'rate_card_version_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_per_day' => Money::class,
            'sell_per_day' => Money::class,
        ];
    }
}
