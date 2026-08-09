<?php

namespace App\Models;

use App\Enums\RecordVisibility;
use App\Enums\RiskRating;
use App\Enums\RiskStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\RiskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * An entry in the risk register (FA-19): probability × impact, an owner who
 * carries it, the records it threatens, a mitigation plan, and exposure as
 * structured effort — days per rate card role — priced at the version pinned
 * when the risk was raised. The euro figure is derived, never typed, and
 * probability-weights into the margin risk band (FA-17).
 *
 * Every re-rating appends a revision, because a register that shows only
 * today's rating cannot tell a risk that was always high from one that is
 * getting worse — and worsening is what has to surface on Today (FA-25).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property string $title
 * @property string|null $description
 * @property RiskRating $probability
 * @property RiskRating $impact
 * @property RiskStatus $status
 * @property string|null $owner_id
 * @property string|null $mitigation
 * @property RecordVisibility $visibility
 * @property string|null $rate_card_version_id
 * @property CarbonImmutable|null $closed_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read User|null $owner
 * @property-read User|null $createdBy
 * @property-read RateCardVersion|null $rateCardVersion
 * @property-read EloquentCollection<int, RiskExposure> $exposures
 * @property-read EloquentCollection<int, RiskLink> $links
 * @property-read EloquentCollection<int, RiskRevision> $revisions
 */
#[Fillable([
    'title', 'description', 'probability', 'impact', 'status', 'owner_id',
    'mitigation', 'visibility', 'created_by',
])]
class Risk extends Model
{
    /** @use HasFactory<RiskFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'probability' => 'medium',
        'impact' => 'medium',
        'status' => 'open',
        'visibility' => 'internal',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Risk register entries are governance history and cannot be deleted — close the risk instead.');
        });
    }

    /**
     * Re-rate the risk (FA-19). The rating, the status and the exposure it
     * was worth are frozen as a revision whenever any of them moves, so the
     * register carries a history and worsening is detectable rather than
     * merely remembered. Closing stamps the moment the risk stopped
     * threatening the engagement.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function reassess(array $attributes, ?User $actor = null, ?string $note = null): void
    {
        DB::transaction(function () use ($attributes, $actor, $note): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            $before = [
                'probability' => $this->probability,
                'impact' => $this->impact,
                'status' => $this->status,
            ];

            $this->fill($attributes);

            $rated = $this->probability !== $before['probability'] || $this->impact !== $before['impact'];
            $moved = $this->status !== $before['status'];

            if ($this->status->isLive()) {
                $this->closed_at = null;
            } elseif ($moved) {
                $this->closed_at = now();
            }

            $edits = collect($this->getDirty())
                ->except(['probability', 'impact', 'status', 'closed_at', 'updated_at'])
                ->all();

            $this->save();

            /*
             * The rating did not move, but somebody rewrote the entry — a new
             * owner, a mitigation plan, a change of visibility. FA-21 asks the
             * trail to carry every governance action, so an edit that appends
             * no revision still appends an entry.
             */
            if (! $rated && ! $moved) {
                if ($edits !== []) {
                    AuditLog::record('risk.updated', $this, [
                        'risk' => $this->title,
                        'changes' => $edits,
                        'note' => $note,
                        'updated_by' => $actor?->name,
                    ]);
                }

                return;
            }

            $this->recordRevision($actor, $note);

            AuditLog::record('risk.reassessed', $this, [
                'risk' => $this->title,
                'from' => [
                    'probability' => $before['probability']->value,
                    'impact' => $before['impact']->value,
                    'status' => $before['status']->value,
                ],
                'to' => [
                    'probability' => $this->probability->value,
                    'impact' => $this->impact->value,
                    'status' => $this->status->value,
                ],
                'score' => $this->score(),
                'weighted_exposure' => $this->weightedExposure()->format(),
                'changes' => $edits,
                'note' => $note,
                'reassessed_by' => $actor?->name,
            ]);
        });
    }

    /**
     * Freeze the current rating, status and exposure as a revision. Called
     * at creation and on every re-rating so the first row is the baseline
     * the history is read against.
     */
    public function recordRevision(?User $actor = null, ?string $note = null): RiskRevision
    {
        $revision = new RiskRevision([
            'organization_id' => $this->organization_id,
            'probability' => $this->probability,
            'impact' => $this->impact,
            'score' => $this->score(),
            'status' => $this->status,
            'exposure' => $this->exposure(),
            'weighted_exposure' => $this->weightedExposure(),
            'note' => $note,
            'actor_id' => $actor?->id,
        ]);

        $revision->risk_id = $this->id;
        $revision->save();

        $this->unsetRelation('revisions');

        return $revision;
    }

    /**
     * Replace the structured exposure lines (FA-19): effort at risk as days
     * per rate card role, priced at the version pinned on the risk. Roles
     * must come from that version — an exposure priced off an unpinned rate
     * card would not trace to anything.
     *
     * @param  list<array{rate_card_role_id: string, days: float|string}>  $lines
     */
    public function syncExposures(array $lines, ?User $actor = null): void
    {
        DB::transaction(function () use ($lines, $actor): void {
            $this->exposures()->delete();

            foreach ($lines as $line) {
                $this->exposures()->create([
                    'organization_id' => $this->organization_id,
                    'rate_card_role_id' => $line['rate_card_role_id'],
                    'days' => $line['days'],
                ]);
            }

            $this->unsetRelation('exposures');

            AuditLog::record('risk.exposure_updated', $this, [
                'risk' => $this->title,
                'lines' => count($lines),
                'exposure' => $this->exposure()->format(),
                'weighted_exposure' => $this->weightedExposure()->format(),
                'updated_by' => $actor?->name,
            ]);
        });
    }

    /**
     * Replace the records this risk threatens (FA-19).
     *
     * @param  list<array{type: string, id: string}>  $targets
     */
    public function syncLinks(array $targets, ?User $actor = null): void
    {
        $before = $this->linkTitles();

        DB::transaction(function () use ($targets): void {
            $this->links()->delete();

            foreach ($targets as $target) {
                $this->links()->create([
                    'organization_id' => $this->organization_id,
                    'threatened_type' => $target['type'],
                    'threatened_id' => $target['id'],
                ]);
            }
        });

        $this->unsetRelation('links');

        $after = $this->linkTitles();

        if ($before !== $after) {
            AuditLog::record('risk.links_updated', $this, [
                'risk' => $this->title,
                'from' => $before,
                'to' => $after,
                'updated_by' => $actor?->name,
            ]);
        }
    }

    /**
     * The threatened records by name, ordered, so a change to the set can be
     * compared and read back in the trail.
     *
     * @return list<string>
     */
    private function linkTitles(): array
    {
        return array_values($this->links
            ->map(fn (RiskLink $link): string => $link->describe()['title'])
            ->sort()
            ->all());
    }

    /**
     * The register's ordering key: probability × impact, 1 to 9.
     */
    public function score(): int
    {
        return $this->probability->score() * $this->impact->score();
    }

    /**
     * A live high-probability, high-impact risk — the H×H entries FA-19 puts
     * in front of the delivery manager on Today.
     */
    public function isEscalated(): bool
    {
        return $this->status->isLive()
            && $this->probability === RiskRating::High
            && $this->impact === RiskRating::High;
    }

    /**
     * Whether the last re-rating made this risk worse. Read from the frozen
     * revisions rather than from memory, so a risk that got worse and was
     * then mitigated back down stops surfacing.
     */
    public function isWorsening(): bool
    {
        if (! $this->status->isLive()) {
            return false;
        }

        $scores = $this->revisions->pluck('score')->all();

        if (count($scores) < 2) {
            return false;
        }

        return (int) $scores[count($scores) - 1] > (int) $scores[count($scores) - 2];
    }

    /**
     * The full euro exposure: effort at risk priced at the pinned cost
     * rates. Cost-derived, so internal only.
     */
    public function exposure(): Money
    {
        return $this->exposures->reduce(
            fn (Money $sum, RiskExposure $exposure): Money => $sum->add($exposure->cost()),
            Money::zero(),
        );
    }

    /**
     * The exposure that rolls into the margin risk band (FA-17): the full
     * figure weighted by probability, so the band is neither the worst case
     * nor nothing.
     */
    public function weightedExposure(): Money
    {
        return Money::fromCents(
            (int) round($this->exposure()->amount * $this->probability->weight()),
        );
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<RateCardVersion, $this>
     */
    public function rateCardVersion(): BelongsTo
    {
        return $this->belongsTo(RateCardVersion::class);
    }

    /**
     * @return HasMany<RiskExposure, $this>
     */
    public function exposures(): HasMany
    {
        return $this->hasMany(RiskExposure::class);
    }

    /**
     * @return HasMany<RiskLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(RiskLink::class);
    }

    /**
     * @return HasMany<RiskRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(RiskRevision::class)->orderBy('created_at')->orderBy('id');
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
            'visibility' => RecordVisibility::class,
            'closed_at' => 'datetime',
        ];
    }
}
