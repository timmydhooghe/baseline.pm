<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use Database\Factories\RateCardVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One published version of the organization's rate card. Versions are
 * immutable: a rate change publishes the next version, and every baseline
 * pins the version it was priced with so cost and margin figures stay
 * derivable forever. Rates are internal-only and never reach the portal.
 *
 * @property string $id
 * @property string $organization_id
 * @property int $version
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User|null $createdBy
 * @property-read Collection<int, RateCardRole> $roles
 * @property-read int|null $roles_count
 */
#[Fillable(['version', 'created_by'])]
class RateCardVersion extends Model
{
    /** @use HasFactory<RateCardVersionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, RecordsAuditLog;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Rate card versions are immutable; publish a new version instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('Rate card versions are immutable; baselines pin them by version.');
        });
    }

    /**
     * @return HasMany<RateCardRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(RateCardRole::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
