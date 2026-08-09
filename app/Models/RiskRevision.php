<?php

namespace App\Models;

use App\Enums\RiskRating;
use App\Enums\RiskStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A frozen rating of a risk (FA-19): what it was rated, what that scored,
 * and what it was worth in exposure at that moment. Appended on every
 * re-rating and never rewritten — the history is what makes "worsening"
 * a fact rather than an impression.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $risk_id
 * @property RiskRating $probability
 * @property RiskRating $impact
 * @property int $score
 * @property RiskStatus $status
 * @property Money|null $exposure
 * @property Money|null $weighted_exposure
 * @property string|null $note
 * @property string|null $actor_id
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read Risk $risk
 * @property-read User|null $actor
 */
#[Fillable([
    'organization_id', 'probability', 'impact', 'score', 'status',
    'exposure', 'weighted_exposure', 'note', 'actor_id',
])]
class RiskRevision extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Risk revisions are frozen ratings and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Risk revisions are frozen ratings and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Risk, $this>
     */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'probability' => RiskRating::class,
            'impact' => RiskRating::class,
            'status' => RiskStatus::class,
            'score' => 'integer',
            'exposure' => Money::class,
            'weighted_exposure' => Money::class,
            'created_at' => 'datetime',
        ];
    }
}
