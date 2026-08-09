<?php

namespace App\Models;

use App\Enums\DecisionSource;
use App\Enums\DecisionStatus;
use App\Enums\RecordVisibility;
use App\Models\Concerns\BelongsToOrganization;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\DecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A record in the decision ledger (FA-18): what was decided, in what
 * context, against which alternatives, by whom, and at what cost in scope,
 * budget and time. Drafts — raised by hand or proposed from a meeting
 * transcript — carry no governance weight and stay editable. Confirming
 * freezes the record: from then on it is only ever superseded by a later
 * decision that names it, so "why was SSO excluded?" answers with a chain
 * rather than an edit history.
 *
 * Shared records freeze a customer-facing payload at confirmation — budget
 * impact is built out of it structurally, never merely blanked — and the
 * customer's acknowledgment is stored immutably against that snapshot.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property DecisionStatus $status
 * @property DecisionSource $source
 * @property string $title
 * @property string $context
 * @property string|null $decision
 * @property list<array{option: string, why_not: string|null}>|null $alternatives
 * @property list<array{name: string, affiliation: string|null}>|null $participants
 * @property list<array{label: string, url: string|null}>|null $evidence
 * @property string|null $impact_scope
 * @property Money|null $impact_budget
 * @property int|null $impact_timeline_days
 * @property RecordVisibility $visibility
 * @property string|null $supersedes_id
 * @property Carbon|null $decided_on
 * @property string|null $decided_by
 * @property string|null $transcript_excerpt
 * @property string|null $customer_snapshot_id
 * @property CarbonImmutable|null $acknowledged_at
 * @property string|null $acknowledged_by
 * @property string|null $acknowledged_by_name
 * @property string|null $acknowledgement_comment
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read User|null $decidedBy
 * @property-read User|null $createdBy
 * @property-read Stakeholder|null $acknowledgedBy
 * @property-read Snapshot|null $customerSnapshot
 * @property-read Decision|null $supersedes
 * @property-read Decision|null $supersededBy
 * @property-read EloquentCollection<int, DecisionLink> $links
 */
#[Fillable([
    'title', 'context', 'decision', 'alternatives', 'participants', 'evidence',
    'impact_scope', 'impact_budget', 'impact_timeline_days', 'visibility',
    'supersedes_id', 'decided_on', 'decided_by', 'transcript_excerpt', 'source', 'created_by',
])]
class Decision extends Model
{
    /** @use HasFactory<DecisionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'source' => 'manual',
        'visibility' => 'internal',
    ];

    protected static function booted(): void
    {
        static::updating(function (Decision $decision): void {
            $original = DecisionStatus::from((string) $decision->getRawOriginal('status'));

            if (! $original->acceptsEdits()) {
                /*
                 * A confirmed decision is history. The only writes it still
                 * accepts are the ones that record what happened to it: the
                 * customer's acknowledgment, the snapshot they acknowledged,
                 * and being superseded by a later record.
                 */
                $allowed = [
                    'status', 'customer_snapshot_id', 'acknowledged_at', 'acknowledged_by',
                    'acknowledged_by_name', 'acknowledgement_comment', 'updated_at',
                ];

                if (array_diff(array_keys($decision->getDirty()), $allowed) !== []) {
                    throw new LogicException('A confirmed decision is immutable — record a superseding decision instead.');
                }
            }
        });

        static::deleting(function (Decision $decision): void {
            if (! $decision->status->acceptsEdits()) {
                throw new LogicException('Only draft decisions can be deleted — everything confirmed is governance record.');
            }
        });
    }

    /**
     * Confirm the draft into the ledger (FA-18). The outcome and the date it
     * was taken are what make a decision citable, so both are required here
     * rather than at drafting time — a transcript-proposed draft arrives
     * without either. A record that names a predecessor supersedes it in the
     * same transaction, and a shared record freezes the customer-facing
     * payload the customer will acknowledge.
     */
    public function confirm(?User $actor = null): void
    {
        DB::transaction(function () use ($actor): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if (! $this->status->acceptsEdits()) {
                throw ValidationException::withMessages([
                    'status' => __('This decision is already on the ledger.'),
                ]);
            }

            if (blank($this->decision)) {
                throw ValidationException::withMessages([
                    'decision' => __('Record what was decided before confirming — a decision without an outcome cannot be cited.'),
                ]);
            }

            $decidedOn = $this->decided_on;

            if ($decidedOn === null) {
                throw ValidationException::withMessages([
                    'decided_on' => __('Record when the decision was taken — the ledger is read in order.'),
                ]);
            }

            $superseded = $this->resolveSupersededDecision();

            $this->status = DecisionStatus::Confirmed;
            $this->save();

            if ($superseded !== null) {
                $superseded->status = DecisionStatus::Superseded;
                $superseded->save();

                AuditLog::record('decision.superseded', $superseded, [
                    'decision' => $superseded->title,
                    'superseded_by' => $this->title,
                    'superseded_by_id' => $this->id,
                ]);
            }

            if ($this->visibility->isShared()) {
                $this->load(['engagement.customer', 'links.linked']);

                $snapshot = Snapshot::capture($this, $this->snapshotPayload(internal: false), $actor);

                $this->customer_snapshot_id = $snapshot->id;
                $this->save();
            }

            AuditLog::record('decision.confirmed', $this, [
                'decision' => $this->title,
                'decided_on' => $decidedOn->toDateString(),
                'visibility' => $this->visibility->value,
                'supersedes_id' => $this->supersedes_id,
                'confirmed_by' => $actor?->name,
            ]);
        });
    }

    /**
     * Record the customer's acknowledgment of a shared decision (FA-18).
     * Acknowledgment is not approval — it is the customer confirming they
     * have seen the record — but it is stored immutably against the frozen
     * payload they saw, and only once.
     */
    public function acknowledge(Stakeholder $stakeholder, ?string $comment = null): void
    {
        DB::transaction(function () use ($stakeholder, $comment): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if ($stakeholder->customer_id !== $this->engagement->customer_id) {
                throw new LogicException('This stakeholder does not belong to the engagement customer.');
            }

            if (! $this->status->isConfirmed() || ! $this->visibility->isShared()) {
                throw ValidationException::withMessages([
                    'acknowledgement' => __('Only a confirmed decision shared with the customer can be acknowledged.'),
                ]);
            }

            if ($this->acknowledged_at !== null) {
                throw ValidationException::withMessages([
                    'acknowledgement' => __('This decision has already been acknowledged.'),
                ]);
            }

            $this->acknowledged_at = now();
            $this->acknowledged_by = $stakeholder->id;
            $this->acknowledged_by_name = $stakeholder->name;
            $this->acknowledgement_comment = $comment;
            $this->save();

            AuditLog::record('decision.acknowledged', $this, [
                'decision' => $this->title,
                'acknowledged_by' => $stakeholder->name,
                'comment' => $comment,
            ]);
        });
    }

    /**
     * Replace this record's linked records with the given set (FA-18) —
     * baseline items, deliverables, change requests, risks, dependencies or
     * work items, always as records rather than prose. Links stay editable
     * while the decision is a draft only.
     *
     * @param  list<array{type: string, id: string}>  $targets
     */
    public function syncLinks(array $targets): void
    {
        if (! $this->status->acceptsEdits()) {
            throw new LogicException('Linked records can only change while the decision is a draft.');
        }

        DB::transaction(function () use ($targets): void {
            $this->links()->delete();

            foreach ($targets as $target) {
                $this->links()->create([
                    'organization_id' => $this->organization_id,
                    'linked_type' => $target['type'],
                    'linked_id' => $target['id'],
                ]);
            }
        });

        $this->unsetRelation('links');
    }

    /**
     * The supersedes-chain this record sits at the end of, oldest first —
     * the decision it replaced, what that one replaced, and so on.
     *
     * @return list<Decision>
     */
    public function supersedesChain(): array
    {
        $chain = [];
        $current = $this->supersedes;

        while ($current !== null) {
            $chain[] = $current;
            $current = $current->supersedes;
        }

        return array_reverse($chain);
    }

    /**
     * The frozen customer-facing payload of a shared decision (FA-18,
     * FA-27): the record, its alternatives, participants, shared evidence
     * and its scope and timeline impact. Budget impact is a euro figure and
     * is built out structurally — the portal never carries money the
     * customer did not agree to see.
     *
     * @return array<string, mixed>
     */
    public function snapshotPayload(bool $internal): array
    {
        $payload = [
            'kind' => $internal ? 'internal_decision' : 'customer_decision',
            'decision' => [
                'id' => $this->id,
                'title' => $this->title,
                'context' => $this->context,
                'decision' => $this->decision,
                'decided_on' => $this->decided_on?->toDateString(),
                'engagement' => [
                    'id' => $this->engagement->id,
                    'name' => $this->engagement->name,
                ],
                'customer' => [
                    'id' => $this->engagement->customer->id,
                    'name' => $this->engagement->customer->name,
                ],
            ],
            'alternatives' => $this->alternatives ?? [],
            'participants' => $this->participants ?? [],
            'evidence' => $this->evidence ?? [],
            'impact' => [
                'scope' => $this->impact_scope,
                'timeline_days' => $this->impact_timeline_days,
            ],
            'linked_records' => $this->links
                ->map(fn (DecisionLink $link): array => $link->describe())
                ->values()
                ->all(),
        ];

        if (! $internal) {
            return $payload;
        }

        $payload['impact']['budget'] = $this->impact_budget?->toArray();
        $payload['source'] = $this->source->value;
        $payload['transcript_excerpt'] = $this->transcript_excerpt;

        return $payload;
    }

    /**
     * The decision this record replaces, verified to be a confirmed record
     * on the same engagement that nothing else has already replaced.
     */
    protected function resolveSupersededDecision(): ?self
    {
        if ($this->supersedes_id === null) {
            return null;
        }

        $superseded = self::query()->whereKey($this->supersedes_id)->lockForUpdate()->first();

        if ($superseded === null || $superseded->engagement_id !== $this->engagement_id) {
            throw ValidationException::withMessages([
                'supersedes_id' => __('A decision can only supersede another decision on the same engagement.'),
            ]);
        }

        if ($superseded->status === DecisionStatus::Draft) {
            throw ValidationException::withMessages([
                'supersedes_id' => __('A draft is not on the ledger yet — there is nothing to supersede.'),
            ]);
        }

        return $superseded;
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
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Stakeholder, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(Stakeholder::class, 'acknowledged_by');
    }

    /**
     * @return BelongsTo<Snapshot, $this>
     */
    public function customerSnapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class, 'customer_snapshot_id');
    }

    /**
     * @return BelongsTo<Decision, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * @return HasOne<Decision, $this>
     */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    /**
     * @return HasMany<DecisionLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(DecisionLink::class);
    }

    /**
     * @return MorphMany<Snapshot, $this>
     */
    public function snapshots(): MorphMany
    {
        return $this->morphMany(Snapshot::class, 'subject');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DecisionStatus::class,
            'source' => DecisionSource::class,
            'visibility' => RecordVisibility::class,
            'alternatives' => 'array',
            'participants' => 'array',
            'evidence' => 'array',
            'impact_budget' => Money::class,
            'impact_timeline_days' => 'integer',
            'decided_on' => 'date',
            'acknowledged_at' => 'datetime',
        ];
    }
}
