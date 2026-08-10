<?php

namespace App\Models;

use App\Enums\BurnSource;
use App\Models\Concerns\BelongsToOrganization;
use App\ValueObjects\Money;
use Database\Factories\BurnEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of a recorded burn week (FA-16): the days a person or a profile
 * spent, and what those days cost at the role's pinned rate. The euro figure
 * is derived at recording time and frozen — a burn line never takes a typed
 * amount, and never recomputes once the week is on record.
 *
 * `role_name` and `cost_per_day_cents` are denormalized on purpose: the week
 * has to stay readable and re-derivable even after later rate card versions
 * rename or reprice the role it was booked against.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $burn_week_id
 * @property string|null $rate_card_role_id
 * @property string $role_name
 * @property string|null $user_id
 * @property string|null $person_name
 * @property string $days
 * @property BurnSource $source
 * @property Money $cost_per_day
 * @property Money $cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read BurnWeek $burnWeek
 * @property-read RateCardRole|null $role
 * @property-read User|null $user
 */
#[Fillable([
    'organization_id', 'rate_card_role_id', 'role_name', 'user_id',
    'person_name', 'days', 'source', 'cost_per_day', 'cost',
])]
class BurnEntry extends Model
{
    /** @use HasFactory<BurnEntryFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A recorded burn line is immutable — record the week again to correct it.');
        });

        static::deleting(function (): never {
            throw new LogicException('Recorded burn lines are governance history and cannot be deleted.');
        });
    }

    /**
     * Who or what the days are booked against: the named person where one is
     * known, the profile otherwise.
     */
    public function attributedTo(): string
    {
        return $this->person_name ?? $this->role_name;
    }

    /**
     * One person, however their name was typed. Folding and trimming is what
     * makes "the same person" mean the same person across the lines of a
     * week — without it, "Sara" and "sara " each get their own seven days.
     */
    public static function normalizePerson(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $trimmed = mb_trim($name);

        return $trimmed === '' ? null : mb_strtolower($trimmed);
    }

    /**
     * @return BelongsTo<BurnWeek, $this>
     */
    public function burnWeek(): BelongsTo
    {
        return $this->belongsTo(BurnWeek::class);
    }

    /**
     * @return BelongsTo<RateCardRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(RateCardRole::class, 'rate_card_role_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'source' => BurnSource::class,
            'cost_per_day' => Money::class,
            'cost' => Money::class,
        ];
    }
}
