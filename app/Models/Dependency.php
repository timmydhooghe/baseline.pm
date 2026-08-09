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
            /*
             * A dependency nobody owns cannot be chased, and an item owed by
             * the customer that is not shared would never reach their action
             * list. Both are the difference between a register and a to-do
             * list nobody reads.
             */
            $responsible = $dependency->party->isCustomer()
                ? $dependency->responsible_stakeholder_id
                : $dependency->responsible_user_id;

            if ($responsible === null) {
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

            $status = $type->resultingStatus();

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
     * Replace the deliverables and milestones this dependency blocks
     * (FA-20).
     *
     * @param  list<array{type: string, id: string}>  $targets
     */
    public function syncLinks(array $targets): void
    {
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
                $record = $link->linkedRecord();
                $baselineDate = $record instanceof BaselineItem ? $record->baseline_date : null;

                return [
                    'record' => $link->describe(),
                    'baseline_date' => $baselineDate?->toDateString(),
                    'projected_date' => $baselineDate?->copy()->addDays($delay)->toDateString(),
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
     * The person who owes the item, whichever side they sit on.
     */
    public function responsibleName(): ?string
    {
        return $this->party->isCustomer()
            ? $this->responsibleStakeholder?->name
            : $this->responsibleUser?->name;
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
