<?php

namespace App\Models;

use App\Enums\DependencyEventType;
use App\Enums\DependencyParty;
use App\Enums\DependencyStatus;
use App\Enums\RecordVisibility;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\DependencyFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * An entry in the dependency register (FA-20): something the engagement is
 * waiting for, owed by a named person on one side or the other, due on a
 * date. Outstanding items accrue delay day for day against that date, and
 * the delay carries the owing party with it — that attribution is what turns
 * a milestone slip into a defensible fact rather than an argument.
 *
 * The evidence trail is the other half: every request, reminder and
 * escalation is appended and never rewritten, so "we asked four times" is
 * provable. Customer-owed items appear on the customer's portal action list.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property string $title
 * @property string|null $description
 * @property DependencyParty $party
 * @property string|null $responsible_stakeholder_id
 * @property string|null $responsible_user_id
 * @property string $responsible_name
 * @property CarbonImmutable $required_on
 * @property DependencyStatus $status
 * @property CarbonImmutable|null $settled_on
 * @property CarbonImmutable|null $escalated_at
 * @property RecordVisibility $visibility
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read Stakeholder|null $responsibleStakeholder
 * @property-read User|null $responsibleUser
 * @property-read User|null $createdBy
 * @property-read EloquentCollection<int, DependencyEvent> $events
 * @property-read EloquentCollection<int, DependencyLink> $links
 */
#[Fillable([
    'title', 'description', 'party', 'responsible_stakeholder_id', 'responsible_user_id',
    'required_on', 'visibility', 'created_by',
])]
class Dependency extends Model
{
    /** @use HasFactory<DependencyFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'visibility' => 'shared',
    ];

    protected static function booted(): void
    {
        static::saving(function (Dependency $dependency): void {
            $person = $dependency->resolveResponsiblePerson();

            /*
             * The name is denormalized on every write, so the record keeps
             * naming who owed it even after that person leaves the
             * organization or the customer's contact list.
             */
            if ($person !== null) {
                $dependency->responsible_name = $person->name;
            }

            /*
             * An outstanding item nobody owns cannot be chased — that is the
             * difference between a register and a to-do list nobody reads.
             * A settled item keeps only its snapshot: closing out work whose
             * owner has since left must not require inventing a new one.
             */
            if ($person === null && $dependency->status->isOutstanding()) {
                throw ValidationException::withMessages([
                    'responsible' => $dependency->party->isCustomer()
                        ? __('Name the customer stakeholder who owes this — "the client" cannot be chased.')
                        : __('Name the colleague who owes this — a dependency without a person is a wish.'),
                ]);
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Dependency register entries are governance history and cannot be deleted — waive the item instead.');
        });
    }

    /**
     * Append an entry to the evidence trail (FA-20) and move the register
     * with it: a request or escalation changes the state, a reminder only
     * proves the item was chased. Receiving stamps the arrival date the
     * delay is measured against; waiving closes the item without one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordEvent(DependencyEventType $type, array $attributes = [], ?User $actor = null): DependencyEvent
    {
        return DB::transaction(function () use ($type, $attributes, $actor): DependencyEvent {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if (! $this->status->isOutstanding()) {
                throw ValidationException::withMessages([
                    'type' => __('This dependency is settled — its trail is closed.'),
                ]);
            }

            $occurredAt = isset($attributes['occurred_at'])
                ? CarbonImmutable::parse($attributes['occurred_at'])
                : CarbonImmutable::now();

            if ($occurredAt->isFuture()) {
                throw ValidationException::withMessages([
                    'occurred_at' => __('An evidence trail records what happened, not what is planned.'),
                ]);
            }

            $event = new DependencyEvent([
                'organization_id' => $this->organization_id,
                'type' => $type,
                'channel' => $attributes['channel'] ?? null,
                'note' => $attributes['note'] ?? null,
                'evidence_url' => $attributes['evidence_url'] ?? null,
                'actor_id' => $actor?->id,
                'occurred_at' => $occurredAt,
            ]);
            $event->dependency_id = $this->id;
            $event->save();

            /*
             * The trail records everything, but the register only moves
             * forward: a request logged after an escalation is still
             * evidence of chasing, and must not quietly demote the item to
             * "requested" while the escalation stamp stays on the record.
             */
            $status = $type->resultingStatus();

            if ($status !== null && ! $this->status->precedes($status)) {
                $status = null;
            }

            if ($status !== null) {
                $this->status = $status;

                /*
                 * Both arrival and waiving stop the delay clock, on the day
                 * the event says it happened — never on the day someone got
                 * round to recording it.
                 */
                if (! $status->isOutstanding()) {
                    $this->settled_on = $occurredAt->startOfDay();
                }

                if ($type === DependencyEventType::Escalated) {
                    $this->escalated_at = $occurredAt;
                }

                $this->save();
            }

            AuditLog::record("dependency.{$type->value}", $this, [
                'dependency' => $this->title,
                'party' => $this->party->value,
                'required_on' => $this->required_on->toDateString(),
                'delay_days' => $this->delayDays(),
                'channel' => $event->channel,
                'note' => $event->note,
                'recorded_by' => $actor?->name,
            ]);

            return $event;
        });
    }

    /**
     * Apply an edit to an outstanding item (FA-20), locking and re-reading
     * first: an arrival recorded between loading the form and submitting it
     * would otherwise let a stale request move the required date a delay was
     * already attributed against.
     *
     * @param  callable(self): array<string, mixed>  $attributes
     * @param  list<array{type: string, id: string}>  $links
     */
    public function updateOutstanding(callable $attributes, array $links, ?User $actor = null): void
    {
        DB::transaction(function () use ($attributes, $links, $actor): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if (! $this->status->isOutstanding()) {
                throw ValidationException::withMessages([
                    'title' => __('This dependency was settled while you were working on it — its record and trail are closed.'),
                ]);
            }

            $this->fill($attributes($this));

            $changes = collect($this->getDirty())->except('updated_at')->keys()->all();

            $this->save();

            if ($changes !== []) {
                AuditLog::record('dependency.updated', $this, [
                    'dependency' => $this->title,
                    'changed' => $changes,
                    'required_on' => $this->required_on->toDateString(),
                    'responsible' => $this->responsibleName(),
                    'updated_by' => $actor?->name,
                ]);
            }

            $this->syncLinks($links, $actor);
        });
    }

    /**
     * Replace the deliverables and milestones this dependency blocks
     * (FA-20).
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
                    'affected_type' => $target['type'],
                    'affected_id' => $target['id'],
                ]);
            }
        });

        $this->unsetRelation('links');

        $after = $this->linkTitles();

        if ($before !== $after) {
            AuditLog::record('dependency.links_updated', $this, [
                'dependency' => $this->title,
                'from' => $before,
                'to' => $after,
                'updated_by' => $actor?->name,
            ]);
        }
    }

    /**
     * The blocked records by name, ordered, so a change to the set can be
     * compared and read back in the trail.
     *
     * @return list<string>
     */
    private function linkTitles(): array
    {
        return array_values($this->links
            ->map(fn (DependencyLink $link): string => $link->describe()['title'])
            ->sort()
            ->all());
    }

    /**
     * The day-for-day delay this dependency has caused: whole days between
     * the required date and the day it settled — or today, while it is still
     * outstanding and the clock is still running. An item that arrived early
     * or on time causes none.
     */
    public function delayDays(?DateTimeInterface $asOf = null): int
    {
        $reference = $this->settled_on !== null
            ? CarbonImmutable::parse($this->settled_on)
            : CarbonImmutable::parse($asOf ?? now());

        $delay = CarbonImmutable::parse($this->required_on)->startOfDay()
            ->diffInDays($reference->startOfDay(), absolute: false);

        return (int) max(0, (int) $delay);
    }

    /**
     * Whether the item is still owed after its required date — the register
     * entries that need chasing today.
     */
    public function isLate(?DateTimeInterface $asOf = null): bool
    {
        return $this->status->isOutstanding() && $this->delayDays($asOf) > 0;
    }

    /**
     * Who a resulting slip is attributed to (FA-20). Delay attribution feeds
     * the decision ledger: a milestone that moved because the customer was
     * late is a very different record from one that moved because we were.
     */
    public function attribution(): DependencyParty
    {
        return $this->party;
    }

    /**
     * The day-for-day consequence on the records this dependency blocks: the
     * baseline date each affected record carries, and where it lands once
     * the accrued delay is applied. Records without a date carry none — a
     * projected date invented out of nothing would be worse than silence.
     *
     * @return list<array{record: array{type: string, type_label: string, id: string, title: string}, baseline_date: string|null, projected_date: string|null}>
     */
    public function projectedImpact(?DateTimeInterface $asOf = null): array
    {
        $delay = $this->delayDays($asOf);

        $impact = $this->links
            ->map(function (DependencyLink $link) use ($delay): array {
                /*
                 * A milestone is dated by the baseline; a deliverable record
                 * is dated by its own forecast, falling back to the
                 * milestone it is assigned to. Either way the dependency
                 * pushes that date out day for day.
                 */
                $record = $link->linkedRecord();

                $dated = match (true) {
                    $record instanceof BaselineItem => $record->baseline_date,
                    $record instanceof Deliverable => $record->forecast_date
                        ?? $record->milestoneItem?->baseline_date,
                    default => null,
                };

                return [
                    'record' => $link->describe(),
                    'baseline_date' => $dated?->toDateString(),
                    'projected_date' => $dated?->copy()->addDays($delay)->toDateString(),
                ];
            })
            ->values()
            ->all();

        return array_values($impact);
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /**
     * @return BelongsTo<Stakeholder, $this>
     */
    public function responsibleStakeholder(): BelongsTo
    {
        return $this->belongsTo(Stakeholder::class, 'responsible_stakeholder_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<DependencyEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DependencyEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * @return HasMany<DependencyLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(DependencyLink::class);
    }

    /**
     * The person who owes the item, whichever side they sit on — read from
     * the snapshot, so a record whose owner has since been removed still
     * says whose item it was.
     */
    public function responsibleName(): ?string
    {
        return $this->responsible_name;
    }

    /**
     * Whether an outstanding item lost the person who owed it, because that
     * colleague or contact was removed. The record keeps their name; the
     * chase needs a new owner.
     */
    public function needsReassignment(): bool
    {
        $responsibleId = $this->party->isCustomer()
            ? $this->responsible_stakeholder_id
            : $this->responsible_user_id;

        return $responsibleId === null && $this->status->isOutstanding();
    }

    /**
     * The responsible person as they exist right now, read fresh so a
     * just-changed reference is never answered from a stale relation.
     */
    protected function resolveResponsiblePerson(): Stakeholder|User|null
    {
        if ($this->party->isCustomer()) {
            return $this->responsible_stakeholder_id === null
                ? null
                : Stakeholder::query()->find($this->responsible_stakeholder_id);
        }

        return $this->responsible_user_id === null
            ? null
            : User::query()->find($this->responsible_user_id);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'party' => DependencyParty::class,
            'status' => DependencyStatus::class,
            'visibility' => RecordVisibility::class,
            'required_on' => 'date',
            'settled_on' => 'date',
            'escalated_at' => 'datetime',
        ];
    }
}
