<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\BurnWeekFactory;
use DateTimeInterface;
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
 * A week of recorded burn (FA-16), frozen at the moment it was recorded: days
 * per person or profile, priced at the rate card version pinned to the week.
 * The snapshot is immutable — a correction records the week again and the
 * earlier entry is marked superseded rather than rewritten, so the ledger can
 * always show what was believed at the time and what replaced it.
 *
 * Cost-to-date, forecast-at-completion, margin forecast and budget % all read
 * the current entry per week; superseded entries stay for the trail only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property Carbon $week_start
 * @property string|null $rate_card_version_id
 * @property Money $cost
 * @property string|null $note
 * @property CarbonImmutable $recorded_at
 * @property string|null $recorded_by
 * @property CarbonImmutable|null $superseded_at
 * @property string|null $superseded_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read RateCardVersion|null $rateCardVersion
 * @property-read User|null $recordedBy
 * @property-read self|null $supersededBy
 * @property-read Collection<int, BurnEntry> $entries
 */
#[Fillable(['week_start', 'note', 'recorded_at', 'recorded_by'])]
class BurnWeek extends Model
{
    /** @use HasFactory<BurnWeekFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::updating(function (BurnWeek $week): void {
            /*
             * Recording freezes the week. Only the supersede stamp — the
             * pointer to the correction that replaced it — may still be
             * written, and only once.
             */
            $allowed = ['superseded_at', 'superseded_by_id', 'updated_at'];

            if (array_diff(array_keys($week->getDirty()), $allowed) !== []) {
                throw new LogicException('A recorded burn week is immutable — record the week again to correct it.');
            }

            if ($week->getRawOriginal('superseded_at') !== null) {
                throw new LogicException('This burn week was already superseded by a later recording.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Recorded burn weeks are governance history and cannot be deleted.');
        });
    }

    /**
     * The Monday of the week an arbitrary date falls in. Weeks are the unit
     * burn is recorded in, so every date the product handles collapses to
     * one — a week identified two different ways would record twice.
     */
    public static function startOfWeekFor(DateTimeInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfWeek();
    }

    /**
     * Whether this recording is the one the money reads: the latest for its
     * week. Superseded entries stay on the ledger as history.
     */
    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * The days recorded per rate card role name, for the forecast's remaining
     * effort. Grouped by name rather than by role id: a change request can
     * pin a later rate card version, and the same profile has to keep
     * accumulating against its plan across versions.
     *
     * @return array<string, float>
     */
    public function daysByRoleName(): array
    {
        $days = [];

        foreach ($this->entries as $entry) {
            $days[$entry->role_name] = ($days[$entry->role_name] ?? 0.0) + (float) $entry->days;
        }

        return $days;
    }

    /**
     * The total days recorded in the week, whoever spent them.
     */
    public function days(): float
    {
        return (float) $this->entries->sum(fn (BurnEntry $entry): float => (float) $entry->days);
    }

    /**
     * A week as a label a manager reads: "4 Aug – 10 Aug 2026".
     */
    public static function labelFor(DateTimeInterface|string $weekStart): string
    {
        $start = self::startOfWeekFor($weekStart);

        return $start->format('j M').' – '.$start->addDays(6)->format('j M Y');
    }

    public function label(): string
    {
        return self::labelFor($this->week_start);
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /**
     * @return BelongsTo<RateCardVersion, $this>
     */
    public function rateCardVersion(): BelongsTo
    {
        return $this->belongsTo(RateCardVersion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * @return HasMany<BurnEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(BurnEntry::class)->orderBy('role_name')->orderBy('person_name');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'cost' => Money::class,
            'recorded_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
