<?php

namespace App\Models;

use App\Enums\BaselineDecision;
use App\Enums\BaselineItemType;
use App\Enums\BaselineStatus;
use App\Enums\CommercialModel;
use App\Enums\EngagementStatus;
use App\Enums\ExecutionMode;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use App\Notifications\BaselineSubmitted;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\BaselineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A versioned commitment ledger for an engagement (FA-5, FA-6). Drafted in
 * the six-step builder wizard, submitted as an immutable review snapshot and
 * approved by the customer into baseline vN. Approved baselines are never
 * edited in place — every approved change request creates the next version —
 * and a submitted baseline is frozen while it awaits the customer's decision.
 * Cost and margin derive from the pinned rate card version and never reach
 * the customer-facing snapshot.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property int $version
 * @property BaselineStatus $status
 * @property CommercialModel $commercial_model
 * @property Money $contract_value
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property ExecutionMode $execution_mode
 * @property string|null $rate_card_version_id
 * @property array<string, array{acknowledged_by: string, acknowledged_by_name: string, acknowledged_at: string, fingerprint?: string}> $acknowledged_checks
 * @property string|null $review_snapshot_id
 * @property string|null $customer_snapshot_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $approved_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read RateCardVersion|null $rateCardVersion
 * @property-read User|null $createdBy
 * @property-read Snapshot|null $reviewSnapshot
 * @property-read Snapshot|null $customerSnapshot
 * @property-read Collection<int, BaselineItem> $items
 * @property-read Collection<int, BaselineAllocation> $allocations
 * @property-read Collection<int, BaselineDocument> $documents
 * @property-read Collection<int, BaselineResponse> $responses
 */
#[Fillable(['version', 'commercial_model', 'contract_value', 'start_date', 'end_date', 'execution_mode', 'created_by'])]
class Baseline extends Model
{
    /** @use HasFactory<BaselineFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, RecordsAuditLog;

    /**
     * The completeness check keys of wizard step 5, in display order.
     *
     * @var list<string>
     */
    public const array CHECK_KEYS = [
        'deliverable_details',
        'milestone_details',
        'values_match_contract',
        'cost_budgets',
        'verification_methods',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'acknowledged_checks' => '{}',
    ];

    protected static function booted(): void
    {
        static::updating(function (Baseline $baseline): void {
            $original = BaselineStatus::from((string) $baseline->getRawOriginal('status'));

            if ($original === BaselineStatus::Approved) {
                throw new LogicException('Approved baselines are immutable; changes go through a change request.');
            }

            if ($original === BaselineStatus::AwaitingApproval) {
                $allowed = ['status', 'approved_at', 'updated_at'];

                if (array_diff(array_keys($baseline->getDirty()), $allowed) !== []) {
                    throw new LogicException('A submitted baseline is frozen while it awaits approval.');
                }
            }
        });

        static::deleting(function (Baseline $baseline): void {
            if ($baseline->status !== BaselineStatus::Draft) {
                throw new LogicException('Submitted and approved baselines cannot be deleted.');
            }
        });
    }

    /**
     * Run a draft mutation serialized against submission and other editors:
     * the baseline row is locked, its state re-read under the lock, and the
     * mutation refused if the baseline left draft in the meantime. Every
     * write to a draft (details, items, documents, role mix, acknowledgements)
     * goes through here so nothing can slip in beside a submission snapshot.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $mutation
     * @return TReturn
     */
    public function mutateAsDraft(Closure $mutation): mixed
    {
        return DB::transaction(function () use ($mutation): mixed {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if ($this->status !== BaselineStatus::Draft) {
                throw ValidationException::withMessages([
                    'baseline' => __('This baseline left draft while you were editing it.'),
                ]);
            }

            return $mutation();
        });
    }

    /**
     * Submit the draft for customer approval: freeze an internal review
     * snapshot plus a customer-facing one with all cost and margin stripped,
     * then move the engagement to awaiting baseline approval. Every failing
     * completeness check must have been fixed or acknowledged.
     *
     * The baseline row is locked and all state re-read under that lock, so
     * the snapshots freeze exactly the committed draft — concurrent draft
     * edits either land before the freeze or are refused by mutateAsDraft().
     */
    public function submitForApproval(?User $submitter = null): void
    {
        DB::transaction(function () use ($submitter): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();
            $this->load(['items.owner', 'allocations.role', 'documents', 'rateCardVersion', 'engagement.customer']);

            if (! $this->status->canTransitionTo(BaselineStatus::AwaitingApproval)) {
                throw new LogicException("A baseline cannot be submitted from [{$this->status->value}].");
            }

            $unresolved = array_column(
                array_filter($this->completenessChecks(), fn (array $check): bool => ! $check['passed'] && ! $check['acknowledged']),
                'label',
            );

            if ($unresolved !== []) {
                throw ValidationException::withMessages([
                    'checks' => __('Fix or acknowledge every completeness warning before submitting: :checks', [
                        'checks' => implode(' ', $unresolved),
                    ]),
                ]);
            }

            $review = Snapshot::capture($this, $this->snapshotPayload(internal: true), $submitter);
            $customer = Snapshot::capture($this, $this->snapshotPayload(internal: false), $submitter);

            $this->status = BaselineStatus::AwaitingApproval;
            $this->submitted_at = now();
            $this->review_snapshot_id = $review->id;
            $this->customer_snapshot_id = $customer->id;
            $this->save();

            AuditLog::record('baseline.submitted', $this, [
                'version' => $this->version,
                'review_snapshot_id' => $review->id,
                'customer_snapshot_id' => $customer->id,
            ]);

            if ($this->engagement->status === EngagementStatus::PreparingBaseline) {
                $this->engagement->transitionTo(EngagementStatus::AwaitingBaselineApproval);
            }
        });

        foreach ($this->approvers() as $approver) {
            $approver->notify(new BaselineSubmitted($this));
        }
    }

    /**
     * Approve the submitted baseline into the engagement's active version.
     * Without a stakeholder this records an internal approval (e.g. the
     * engagement being moved to Active before the portal flow exists).
     */
    public function approve(?Stakeholder $approver = null, ?string $comment = null): void
    {
        if ($this->status !== BaselineStatus::AwaitingApproval) {
            throw new LogicException('Only a baseline awaiting approval can be approved.');
        }

        DB::transaction(function () use ($approver, $comment): void {
            $this->status = BaselineStatus::Approved;
            $this->approved_at = now();
            $this->save();

            AuditLog::record('baseline.approved', $this, [
                'version' => $this->version,
                'approved_by' => $approver?->name,
                'comment' => $comment,
            ]);

            /*
             * Approval turns commitments into execution: every deliverable
             * item gets its living acceptance record (FA-22).
             */
            Deliverable::provisionForBaseline($this);

            if ($this->engagement->status === EngagementStatus::AwaitingBaselineApproval) {
                $this->engagement->transitionTo(EngagementStatus::Active);
            }
        });
    }

    /**
     * Return a submitted baseline to draft after a rejection, clarification
     * request or internal withdrawal. The review snapshots are preserved —
     * only the working copy reopens for editing.
     */
    public function returnToDraft(string $reason, ?Stakeholder $decidedBy = null, ?string $comment = null): void
    {
        if ($this->status !== BaselineStatus::AwaitingApproval) {
            throw new LogicException('Only a baseline awaiting approval can return to draft.');
        }

        DB::transaction(function () use ($reason, $decidedBy, $comment): void {
            $this->status = BaselineStatus::Draft;
            $this->save();

            AuditLog::record('baseline.returned_to_draft', $this, [
                'version' => $this->version,
                'reason' => $reason,
                'decided_by' => $decidedBy?->name,
                'comment' => $comment,
            ]);

            if ($this->engagement->status === EngagementStatus::AwaitingBaselineApproval) {
                $this->engagement->transitionTo(EngagementStatus::PreparingBaseline);
            }
        });
    }

    /**
     * Record the customer's decision on the frozen submission (FA-27). The
     * response is stored immutably against the customer snapshot it was made
     * on. Approval commits the baseline and activates the engagement;
     * rejection and clarification requests both return the draft to the
     * builder — the snapshots stay on record either way.
     */
    public function recordResponse(Stakeholder $stakeholder, BaselineDecision $decision, ?string $comment = null): BaselineResponse
    {
        if (! $stakeholder->role->canApprove()) {
            throw ValidationException::withMessages([
                'decision' => __('Only stakeholders with approval rights can respond to a baseline.'),
            ]);
        }

        return DB::transaction(function () use ($stakeholder, $decision, $comment): BaselineResponse {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if ($stakeholder->customer_id !== $this->engagement->customer_id) {
                throw new LogicException('This stakeholder does not belong to the engagement customer.');
            }

            if ($this->status !== BaselineStatus::AwaitingApproval || $this->customer_snapshot_id === null) {
                throw ValidationException::withMessages([
                    'decision' => __('This baseline is no longer awaiting a decision.'),
                ]);
            }

            $response = new BaselineResponse([
                'snapshot_id' => $this->customer_snapshot_id,
                'stakeholder_id' => $stakeholder->id,
                'stakeholder_name' => $stakeholder->name,
                'decision' => $decision,
                'comment' => $comment,
            ]);
            $response->organization_id = $this->organization_id;
            $response->baseline_id = $this->id;
            $response->save();

            match ($decision) {
                BaselineDecision::Approved => $this->approve($stakeholder, $comment),
                BaselineDecision::Rejected => $this->returnToDraft('rejected', $stakeholder, $comment),
                BaselineDecision::ClarificationRequested => $this->returnToDraft('clarification_requested', $stakeholder, $comment),
            };

            return $response;
        });
    }

    /**
     * The stakeholders who may decide on this baseline: the engagement
     * customer's contacts with approval rights.
     *
     * @return Collection<int, Stakeholder>
     */
    public function approvers(): Collection
    {
        return $this->engagement->customer->stakeholders
            ->filter(fn (Stakeholder $stakeholder): bool => $stakeholder->role->canApprove())
            ->values();
    }

    /**
     * The machine checks of wizard step 5. Each failing check blocks
     * submission until it is fixed or explicitly acknowledged.
     *
     * @return list<array{key: string, label: string, passed: bool, detail: string, acknowledged: bool, acknowledgedBy: string|null, acknowledgedAt: string|null}>
     */
    public function completenessChecks(): array
    {
        $deliverables = $this->items->where('type', BaselineItemType::Deliverable)->values();
        $milestones = $this->items->where('type', BaselineItemType::Milestone)->values();
        $allocated = $this->allocations->pluck('baseline_item_id')->filter()->unique();

        $missingDetails = $deliverables
            ->filter(fn (BaselineItem $item): bool => $item->owner_id === null || $item->value === null)
            ->pluck('title');

        $missingDates = $milestones
            ->filter(fn (BaselineItem $item): bool => $item->baseline_date === null || $item->payment_trigger === null)
            ->pluck('title');

        $valueSum = $deliverables->reduce(
            fn (Money $sum, BaselineItem $item): Money => $sum->add($item->value ?? Money::zero()),
            Money::zero(),
        );

        $unbudgeted = $deliverables
            ->filter(fn (BaselineItem $item): bool => ! $allocated->contains($item->id))
            ->pluck('title');

        $unverifiable = $deliverables
            ->filter(fn (BaselineItem $item): bool => $item->acceptance_criteria === null
                || $item->acceptance_criteria === []
                || array_any($item->acceptance_criteria, fn (array $criterion): bool => ($criterion['verification_method'] ?? null) === null))
            ->pluck('title');

        return [
            $this->check(
                'deliverable_details',
                __('Every deliverable has an owner and a commercial value'),
                $missingDetails->isEmpty(),
                $missingDetails->isEmpty() ? '' : __('Incomplete: :titles', ['titles' => $missingDetails->implode(', ')]),
            ),
            $this->check(
                'milestone_details',
                __('Every milestone has a baseline date and a payment trigger'),
                $missingDates->isEmpty(),
                $missingDates->isEmpty() ? '' : __('Incomplete: :titles', ['titles' => $missingDates->implode(', ')]),
            ),
            $this->check(
                'values_match_contract',
                __('Deliverable values sum to the contract value'),
                $valueSum->equals($this->contract_value),
                $valueSum->equals($this->contract_value) ? '' : __('Deliverables total :sum against a contract value of :contract.', [
                    'sum' => $valueSum->format(),
                    'contract' => $this->contract_value->format(),
                ]),
            ),
            $this->check(
                'cost_budgets',
                __('Every deliverable has a role-mix cost budget'),
                $unbudgeted->isEmpty(),
                $unbudgeted->isEmpty() ? '' : __('No role mix yet: :titles', ['titles' => $unbudgeted->implode(', ')]),
            ),
            $this->check(
                'verification_methods',
                __('Every deliverable has acceptance criteria with verification methods'),
                $unverifiable->isEmpty(),
                $unverifiable->isEmpty() ? '' : __('Missing or unverifiable criteria: :titles', ['titles' => $unverifiable->implode(', ')]),
            ),
        ];
    }

    /**
     * Record that a manager accepts a failing completeness check as-is.
     * Only a check that is failing right now can be acknowledged, and the
     * acknowledgement is fingerprinted to the exact failure it accepted —
     * if the underlying data changes afterwards, the acknowledgement stops
     * counting and the check blocks submission again.
     */
    public function acknowledgeCheck(string $key, User $user): void
    {
        $this->mutateAsDraft(function () use ($key, $user): void {
            $check = collect($this->completenessChecks())->firstWhere('key', $key);

            if ($check === null || $check['passed']) {
                throw ValidationException::withMessages([
                    'check' => __('Only a failing completeness check can be acknowledged.'),
                ]);
            }

            $acknowledged = $this->acknowledged_checks;
            $acknowledged[$key] = [
                'acknowledged_by' => $user->id,
                'acknowledged_by_name' => $user->name,
                'acknowledged_at' => now()->toIso8601String(),
                'fingerprint' => $this->checkFingerprint($check['detail']),
            ];

            $this->acknowledged_checks = $acknowledged;
            $this->save();
        });
    }

    /**
     * Total cost budget: every role-mix line priced at the pinned rate card
     * version, delivery management included.
     */
    public function costBudget(): Money
    {
        return $this->allocations->reduce(
            fn (Money $sum, BaselineAllocation $allocation): Money => $sum->add($allocation->cost()),
            Money::zero(),
        );
    }

    /**
     * The delivery-management effort not tied to a specific deliverable.
     */
    public function deliveryManagementCost(): Money
    {
        return $this->allocations
            ->whereNull('baseline_item_id')
            ->reduce(
                fn (Money $sum, BaselineAllocation $allocation): Money => $sum->add($allocation->cost()),
                Money::zero(),
            );
    }

    /**
     * Per-deliverable cost budgets: direct role-mix cost plus a pro-rata
     * share of delivery management (by direct cost; evenly when no
     * deliverable has direct cost yet). Shares always sum to the exact
     * delivery-management total.
     *
     * @return array<string, array{direct: Money, budget: Money}>
     */
    public function deliverableCostBudgets(): array
    {
        $deliverables = $this->items->where('type', BaselineItemType::Deliverable)->values();

        if ($deliverables->isEmpty()) {
            return [];
        }

        $direct = $deliverables->mapWithKeys(fn (BaselineItem $item): array => [
            $item->id => $this->allocations
                ->where('baseline_item_id', $item->id)
                ->reduce(
                    fn (Money $sum, BaselineAllocation $allocation): Money => $sum->add($allocation->cost()),
                    Money::zero(),
                ),
        ]);

        $directTotal = $direct->reduce(fn (Money $sum, Money $cost): Money => $sum->add($cost), Money::zero());
        $management = $this->deliveryManagementCost();

        $budgets = [];
        $assigned = 0;

        foreach ($deliverables as $index => $item) {
            /** @var Money $itemDirect */
            $itemDirect = $direct[$item->id];

            /*
             * The pro-rata share is computed via a float ratio instead of
             * intdiv(management × direct, total): two cent amounts multiplied
             * together overflow 64-bit integers for perfectly valid budgets.
             * Each factor stays far below 2^53, the ratio is exact to ~1e-16,
             * and the last deliverable absorbs the remainder so the shares
             * always sum to the exact delivery-management total in cents.
             */
            $share = match (true) {
                $index === $deliverables->count() - 1 => $management->amount - $assigned,
                $directTotal->isZero() => intdiv($management->amount, $deliverables->count()),
                default => (int) floor($itemDirect->amount / $directTotal->amount * $management->amount),
            };
            $assigned += $share;

            $budgets[$item->id] = [
                'direct' => $itemDirect,
                'budget' => $itemDirect->add(Money::fromCents($share)),
            ];
        }

        return $budgets;
    }

    /**
     * Planned margin locked in on approval: contract value minus cost budget.
     */
    public function plannedMargin(): Money
    {
        return $this->contract_value->subtract($this->costBudget());
    }

    /**
     * The blended cost and sell day rates that scope creep pricing derives from
     * (FA-9): the baseline's role mix weighted by allocated days — the
     * planned team's actual composition — falling back to a straight average
     * over the pinned rate card version while no role mix exists. Null when
     * there are no pinned rates to derive from: unpriced beats free-typed.
     *
     * @return array{cost: Money, sell: Money}|null
     */
    public function blendedDayRates(): ?array
    {
        $allocations = $this->allocations->filter(
            fn (BaselineAllocation $allocation): bool => (float) $allocation->days > 0,
        );

        if ($allocations->isNotEmpty()) {
            $totalDays = $allocations->sum(fn (BaselineAllocation $allocation): float => (float) $allocation->days);
            $cost = $allocations->sum(
                fn (BaselineAllocation $allocation): float => (float) $allocation->days * $allocation->role->cost_per_day->amount,
            );
            $sell = $allocations->sum(
                fn (BaselineAllocation $allocation): float => (float) $allocation->days * $allocation->role->sell_per_day->amount,
            );

            return [
                'cost' => Money::fromCents((int) round($cost / $totalDays)),
                'sell' => Money::fromCents((int) round($sell / $totalDays)),
            ];
        }

        $roles = $this->rateCardVersion?->roles;

        if ($roles === null || $roles->isEmpty()) {
            return null;
        }

        return [
            'cost' => Money::fromCents((int) round($roles->sum(fn (RateCardRole $role): int => $role->cost_per_day->amount) / $roles->count())),
            'sell' => Money::fromCents((int) round($roles->sum(fn (RateCardRole $role): int => $role->sell_per_day->amount) / $roles->count())),
        ];
    }

    /**
     * The frozen payload for a review snapshot. The customer variant is
     * built structurally without cost, rate or margin data — those keys are
     * never present, not merely blanked (FA-5 step 6, FA-27).
     *
     * @return array<string, mixed>
     */
    public function snapshotPayload(bool $internal): array
    {
        $payload = [
            'kind' => $internal ? 'internal_review' : 'customer_review',
            'baseline' => [
                'id' => $this->id,
                'version' => $this->version,
                'commercial_model' => $this->commercial_model->value,
                'contract_value' => $this->contract_value->toArray(),
                'start_date' => $this->start_date->toDateString(),
                'end_date' => $this->end_date->toDateString(),
                'execution_mode' => $this->execution_mode->value,
                'engagement' => [
                    'id' => $this->engagement->id,
                    'name' => $this->engagement->name,
                ],
                'customer' => [
                    'id' => $this->engagement->customer->id,
                    'name' => $this->engagement->customer->name,
                ],
            ],
            'documents' => $this->documents
                ->map(fn (BaselineDocument $document): array => [
                    'id' => $document->id,
                    'filename' => $document->filename,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => $document->size_bytes,
                ])
                ->values()
                ->all(),
            'items' => $this->items
                ->map(fn (BaselineItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->type->value,
                    'position' => $item->position,
                    'title' => $item->title,
                    'description' => $item->description,
                    'clause_reference' => $item->clause_reference,
                    'owner' => $item->owner === null ? null : ['id' => $item->owner->id, 'name' => $item->owner->name],
                    'value' => $item->value?->toArray(),
                    'acceptance_criteria' => $item->acceptance_criteria,
                    'baseline_date' => $item->baseline_date?->toDateString(),
                    'payment_trigger' => $item->payment_trigger,
                ])
                ->values()
                ->all(),
        ];

        if (! $internal) {
            return $payload;
        }

        $budgets = $this->deliverableCostBudgets();

        $payload['completeness'] = [
            'checks' => $this->completenessChecks(),
        ];

        $payload['commercials'] = [
            'rate_card_version' => $this->rateCardVersion?->version,
            'allocations' => $this->allocations
                ->map(fn (BaselineAllocation $allocation): array => [
                    'baseline_item_id' => $allocation->baseline_item_id,
                    'role' => $allocation->role->name,
                    'days' => $allocation->days,
                    'cost_per_day' => $allocation->role->cost_per_day->toArray(),
                    'cost' => $allocation->cost()->toArray(),
                ])
                ->values()
                ->all(),
            'delivery_management_cost' => $this->deliveryManagementCost()->toArray(),
            'deliverable_cost_budgets' => array_map(
                fn (array $budget): array => [
                    'direct' => $budget['direct']->toArray(),
                    'budget' => $budget['budget']->toArray(),
                ],
                $budgets,
            ),
            'cost_budget' => $this->costBudget()->toArray(),
            'planned_margin' => $this->plannedMargin()->toArray(),
        ];

        return $payload;
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Snapshot, $this>
     */
    public function reviewSnapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class, 'review_snapshot_id');
    }

    /**
     * @return BelongsTo<Snapshot, $this>
     */
    public function customerSnapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class, 'customer_snapshot_id');
    }

    /**
     * @return HasMany<BaselineItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BaselineItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<BaselineAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(BaselineAllocation::class);
    }

    /**
     * @return HasMany<BaselineDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(BaselineDocument::class);
    }

    /**
     * @return HasMany<BaselineResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(BaselineResponse::class)->latest('created_at');
    }

    /**
     * @return MorphMany<Snapshot, $this>
     */
    public function snapshots(): MorphMany
    {
        return $this->morphMany(Snapshot::class, 'subject');
    }

    /**
     * An acknowledgement only counts while the failure it accepted is still
     * the current one — the fingerprint ties it to the detail text it was
     * recorded against.
     *
     * @return array{key: string, label: string, passed: bool, detail: string, acknowledged: bool, acknowledgedBy: string|null, acknowledgedAt: string|null}
     */
    private function check(string $key, string $label, bool $passed, string $detail): array
    {
        $acknowledgement = $this->acknowledged_checks[$key] ?? null;
        $current = $acknowledgement !== null
            && ($acknowledgement['fingerprint'] ?? null) === $this->checkFingerprint($detail);

        return [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'detail' => $detail,
            'acknowledged' => $current,
            'acknowledgedBy' => $current ? $acknowledgement['acknowledged_by_name'] : null,
            'acknowledgedAt' => $current ? $acknowledgement['acknowledged_at'] : null,
        ];
    }

    private function checkFingerprint(string $detail): string
    {
        return hash('sha256', $detail);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BaselineStatus::class,
            'commercial_model' => CommercialModel::class,
            'execution_mode' => ExecutionMode::class,
            'contract_value' => Money::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'acknowledged_checks' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
