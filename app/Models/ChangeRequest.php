<?php

namespace App\Models;

use App\Enums\BaselineItemType;
use App\Enums\BaselineStatus;
use App\Enums\ChangeRequestDecision;
use App\Enums\ChangeRequestOrigin;
use App\Enums\ChangeRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Notifications\ChangeRequestReminder;
use App\Notifications\ChangeRequestSubmitted;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ChangeRequestFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A request to change the committed baseline (FA-11..13). Born as a draft —
 * from drift triage (FA-9) or raised by hand — it moves through structured
 * assessment (effort as a role mix priced at the pinned rate card version),
 * a customer proposal (numeric price, structured schedule impact) and a
 * frozen portal review to a decision. Approval mints the next baseline
 * version; a clarification request returns it to assessment; approved and
 * rejected are terminal. Cost and margin stay derived and internal-only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property string|null $work_item_id
 * @property ChangeRequestStatus $status
 * @property string $title
 * @property string $what
 * @property string|null $why
 * @property ChangeRequestOrigin|null $origin
 * @property string|null $rate_card_version_id
 * @property float|null $estimated_days
 * @property int $logged_seconds
 * @property Money|null $customer_price
 * @property string|null $impact_milestone_id
 * @property int|null $impact_days
 * @property string|null $scope_added
 * @property string|null $scope_removed
 * @property string|null $alternatives
 * @property string|null $review_snapshot_id
 * @property string|null $customer_snapshot_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $respond_by
 * @property CarbonImmutable|null $last_reminded_at
 * @property CarbonImmutable|null $decided_at
 * @property string|null $minted_baseline_id
 * @property CarbonImmutable|null $work_started_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read WorkItem|null $workItem
 * @property-read User|null $createdBy
 * @property-read RateCardVersion|null $rateCardVersion
 * @property-read BaselineItem|null $impactMilestone
 * @property-read Snapshot|null $reviewSnapshot
 * @property-read Snapshot|null $customerSnapshot
 * @property-read Baseline|null $mintedBaseline
 * @property-read EloquentCollection<int, ChangeRequestAllocation> $allocations
 * @property-read EloquentCollection<int, BaselineItem> $affectedItems
 * @property-read EloquentCollection<int, ChangeRequestResponse> $responses
 */
#[Fillable(['title', 'what', 'why', 'origin', 'estimated_days', 'logged_seconds', 'work_started_at', 'customer_price', 'impact_milestone_id', 'impact_days', 'scope_added', 'scope_removed', 'alternatives', 'created_by'])]
class ChangeRequest extends Model
{
    /** @use HasFactory<ChangeRequestFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * How many days before the respond-by deadline reminders start.
     */
    public const int REMINDER_LEAD_DAYS = 3;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'logged_seconds' => 0,
    ];

    protected static function booted(): void
    {
        static::updating(function (ChangeRequest $changeRequest): void {
            $original = ChangeRequestStatus::from((string) $changeRequest->getRawOriginal('status'));

            if ($original->isDecided()) {
                throw new LogicException('A decided change request is immutable — the decision is on record.');
            }

            if ($original === ChangeRequestStatus::AwaitingApproval) {
                $allowed = ['status', 'decided_at', 'minted_baseline_id', 'last_reminded_at', 'updated_at'];

                if (array_diff(array_keys($changeRequest->getDirty()), $allowed) !== []) {
                    throw new LogicException('A submitted change request is frozen while it awaits the customer decision.');
                }
            }
        });

        static::deleting(function (ChangeRequest $changeRequest): void {
            if ($changeRequest->status !== ChangeRequestStatus::Draft) {
                throw new LogicException('Only draft change requests can be deleted — everything later is governance record.');
            }
        });
    }

    /**
     * Whether execution began before this change request was approved —
     * FA-9's contractual breach risk. The work start is snapshotted at
     * drafting time, so it always predates any approval.
     */
    public function flagsContractualBreach(): bool
    {
        return $this->work_started_at !== null;
    }

    /**
     * Open the structured assessment (FA-12): pin the rate card version the
     * approved baseline was priced with, so every derived number traces to
     * it. Also the way back from a customer proposal that needs rework —
     * the existing pin is kept so priced role lines stay valid.
     */
    public function startAssessment(?User $actor = null): void
    {
        DB::transaction(function () use ($actor): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if (! $this->status->canTransitionTo(ChangeRequestStatus::UnderAssessment)) {
                throw new LogicException("A change request cannot move from [{$this->status->value}] to assessment.");
            }

            $baseline = $this->engagement->approvedBaseline();

            if ($baseline === null) {
                throw ValidationException::withMessages([
                    'status' => __('Assessment prices against the approved baseline — approve a baseline first.'),
                ]);
            }

            $from = $this->status;
            $this->rate_card_version_id ??= $baseline->rate_card_version_id;
            $this->status = ChangeRequestStatus::UnderAssessment;
            $this->save();

            AuditLog::record('change_request.assessment_started', $this, [
                'from' => $from->value,
                'started_by' => $actor?->name,
                'rate_card_version' => $this->rateCardVersion?->version,
            ]);
        });
    }

    /**
     * Move the assessed change to the customer proposal stage. The proposal
     * price suggestion derives from the role mix, so effort must be priced
     * before commercial terms can be drafted.
     */
    public function moveToProposal(?User $actor = null): void
    {
        DB::transaction(function () use ($actor): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if (! $this->status->canTransitionTo(ChangeRequestStatus::CustomerProposal)) {
                throw new LogicException("A change request cannot move from [{$this->status->value}] to a customer proposal.");
            }

            if (! $this->allocations()->exists()) {
                throw ValidationException::withMessages([
                    'allocations' => __('Assess the effort as a role mix first — commercial terms derive from it.'),
                ]);
            }

            $this->status = ChangeRequestStatus::CustomerProposal;
            $this->save();

            AuditLog::record('change_request.proposal_started', $this, [
                'started_by' => $actor?->name,
            ]);
        });
    }

    /**
     * Submit the proposal for customer approval (FA-13): freeze an internal
     * review snapshot plus a customer-facing one with all cost and margin
     * stripped, stamp the respond-by deadline, and notify every stakeholder
     * with approval rights. The row is locked and re-read so the snapshots
     * freeze exactly the committed proposal.
     */
    public function submitToCustomer(DateTimeInterface|string $respondBy, ?User $submitter = null): void
    {
        $respondBy = CarbonImmutable::parse($respondBy)->endOfDay();

        DB::transaction(function () use ($respondBy, $submitter): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();
            $this->load(['allocations.role', 'affectedItems', 'impactMilestone', 'rateCardVersion', 'engagement.customer', 'workItem']);

            if (! $this->status->canTransitionTo(ChangeRequestStatus::AwaitingApproval)) {
                throw new LogicException("A change request cannot be submitted from [{$this->status->value}].");
            }

            if ($this->customer_price === null) {
                throw ValidationException::withMessages([
                    'customer_price' => __('Set the customer price before submitting — the customer approves a number, not a promise.'),
                ]);
            }

            /*
             * The role mix was required to enter the proposal stage, but it
             * stays editable there — without this recheck a cleared mix
             * could freeze a proposal whose approval would mint a deliverable
             * with no cost budget.
             */
            if ($this->allocations->isEmpty()) {
                throw ValidationException::withMessages([
                    'allocations' => __('Assess the effort as a role mix first — commercial terms derive from it.'),
                ]);
            }

            /*
             * A frozen proposal can only be reopened by the customer, so
             * submitting with nobody able to decide would strand it in
             * awaiting-approval forever.
             */
            if ($this->approvers()->isEmpty()) {
                throw ValidationException::withMessages([
                    'approvers' => __('The customer has no stakeholder with approval rights — add an approver before submitting.'),
                ]);
            }

            if ($respondBy->isPast()) {
                throw ValidationException::withMessages([
                    'respond_by' => __('The respond-by deadline must lie in the future.'),
                ]);
            }

            $this->respond_by = $respondBy;

            $review = Snapshot::capture($this, $this->snapshotPayload(internal: true), $submitter);
            $customer = Snapshot::capture($this, $this->snapshotPayload(internal: false), $submitter);

            $this->status = ChangeRequestStatus::AwaitingApproval;
            $this->submitted_at = now();
            $this->last_reminded_at = null;
            $this->review_snapshot_id = $review->id;
            $this->customer_snapshot_id = $customer->id;
            $this->save();

            AuditLog::record('change_request.submitted', $this, [
                'respond_by' => $respondBy->toDateString(),
                'price' => $this->customer_price->format(),
                'review_snapshot_id' => $review->id,
                'customer_snapshot_id' => $customer->id,
            ]);
        });

        foreach ($this->approvers() as $approver) {
            $approver->notify(new ChangeRequestSubmitted($this));
        }
    }

    /**
     * Record the customer's decision on the frozen proposal (FA-13). The
     * response is stored immutably against the snapshot it was made on.
     * Approval mints the next baseline version, rejection is terminal, and a
     * clarification request reopens the assessment.
     */
    public function recordResponse(Stakeholder $stakeholder, ChangeRequestDecision $decision, ?string $comment = null): ChangeRequestResponse
    {
        if (! $stakeholder->role->canApprove()) {
            throw ValidationException::withMessages([
                'decision' => __('Only stakeholders with approval rights can respond to a change request.'),
            ]);
        }

        return DB::transaction(function () use ($stakeholder, $decision, $comment): ChangeRequestResponse {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if ($stakeholder->customer_id !== $this->engagement->customer_id) {
                throw new LogicException('This stakeholder does not belong to the engagement customer.');
            }

            if ($this->status !== ChangeRequestStatus::AwaitingApproval || $this->customer_snapshot_id === null) {
                throw ValidationException::withMessages([
                    'decision' => __('This proposal is no longer awaiting a decision.'),
                ]);
            }

            $response = new ChangeRequestResponse([
                'snapshot_id' => $this->customer_snapshot_id,
                'stakeholder_id' => $stakeholder->id,
                'stakeholder_name' => $stakeholder->name,
                'decision' => $decision,
                'comment' => $comment,
            ]);
            $response->organization_id = $this->organization_id;
            $response->change_request_id = $this->id;
            $response->save();

            match ($decision) {
                ChangeRequestDecision::Approved => $this->applyApproval($stakeholder, $comment),
                ChangeRequestDecision::Rejected => $this->applyRejection($stakeholder, $comment),
                ChangeRequestDecision::ClarificationRequested => $this->applyClarification($stakeholder, $comment),
            };

            return $response;
        });
    }

    /**
     * Re-notify every approver of a proposal whose respond-by deadline is
     * near or past, and keep the nudge on the audit record.
     */
    public function remindApprovers(): void
    {
        if ($this->status !== ChangeRequestStatus::AwaitingApproval || $this->respond_by === null) {
            return;
        }

        foreach ($this->approvers() as $approver) {
            $approver->notify(new ChangeRequestReminder($this));
        }

        $this->last_reminded_at = now();
        $this->save();

        AuditLog::record('change_request.reminded', $this, [
            'respond_by' => $this->respond_by->toDateString(),
            'overdue' => $this->respond_by->isPast(),
        ]);
    }

    /**
     * The stakeholders who may decide on this proposal: the engagement
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
     * The derived internal cost of the assessed role mix — never typed,
     * never client-visible.
     */
    public function cost(): Money
    {
        return $this->allocations->reduce(
            fn (Money $sum, ChangeRequestAllocation $allocation): Money => $sum->add($allocation->cost()),
            Money::zero(),
        );
    }

    /**
     * The suggested customer price: the role mix at the pinned sell rates —
     * cost times the target margin the rate card embodies per role (FA-12).
     * Null while no effort is assessed: nothing to suggest beats a made-up
     * number.
     */
    public function suggestedPrice(): ?Money
    {
        if ($this->allocations->isEmpty()) {
            return null;
        }

        return $this->allocations->reduce(
            fn (Money $sum, ChangeRequestAllocation $allocation): Money => $sum->add($allocation->suggestedPrice()),
            Money::zero(),
        );
    }

    /**
     * The derived margin of the proposal: customer price minus derived cost.
     */
    public function margin(): ?Money
    {
        if ($this->customer_price === null) {
            return null;
        }

        return $this->customer_price->subtract($this->cost());
    }

    /**
     * The derived margin as a percentage of the customer price, one decimal.
     */
    public function marginPercent(): ?float
    {
        $margin = $this->margin();

        if ($margin === null || $this->customer_price === null || $this->customer_price->isZero()) {
            return null;
        }

        return round($margin->amount / $this->customer_price->amount * 100, 1);
    }

    /**
     * The frozen payload for a review snapshot. The customer variant carries
     * price, scope and schedule only — cost, rates and margin are built out
     * structurally, never merely blanked (FA-13, FA-27).
     *
     * @return array<string, mixed>
     */
    public function snapshotPayload(bool $internal): array
    {
        $projected = $this->impactMilestone?->baseline_date !== null && $this->impact_days !== null
            ? $this->impactMilestone->baseline_date->copy()->addDays($this->impact_days)->toDateString()
            : null;

        $payload = [
            'kind' => $internal ? 'internal_review' : 'customer_review',
            'change_request' => [
                'id' => $this->id,
                'title' => $this->title,
                'what' => $this->what,
                'why' => $this->why,
                'origin' => $this->origin?->value,
                'engagement' => [
                    'id' => $this->engagement->id,
                    'name' => $this->engagement->name,
                ],
                'customer' => [
                    'id' => $this->engagement->customer->id,
                    'name' => $this->engagement->customer->name,
                ],
            ],
            'price' => $this->customer_price?->toArray(),
            'scope' => [
                'added' => $this->scope_added,
                'removed' => $this->scope_removed,
                'alternatives' => $this->alternatives,
            ],
            'affected_items' => $this->affectedItems
                ->map(fn (BaselineItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->type->value,
                    'title' => $item->title,
                ])
                ->values()
                ->all(),
            'schedule_impact' => $this->impactMilestone === null ? null : [
                'milestone' => [
                    'id' => $this->impactMilestone->id,
                    'title' => $this->impactMilestone->title,
                ],
                'baseline_date' => $this->impactMilestone->baseline_date?->toDateString(),
                'days' => $this->impact_days,
                'projected_date' => $projected,
            ],
            'respond_by' => $this->respond_by?->toDateString(),
        ];

        if (! $internal) {
            return $payload;
        }

        $payload['origin_work'] = $this->workItem === null ? null : [
            'id' => $this->workItem->id,
            'key' => $this->workItem->external_key,
            'title' => $this->workItem->title,
        ];

        $payload['assessment'] = [
            'rate_card_version' => $this->rateCardVersion?->version,
            'allocations' => $this->allocations
                ->map(fn (ChangeRequestAllocation $allocation): array => [
                    'role' => $allocation->role->name,
                    'days' => $allocation->days,
                    'cost_per_day' => $allocation->role->cost_per_day->toArray(),
                    'sell_per_day' => $allocation->role->sell_per_day->toArray(),
                    'cost' => $allocation->cost()->toArray(),
                ])
                ->values()
                ->all(),
            'evidence' => [
                'estimated_days' => $this->estimated_days,
                'logged_seconds' => $this->logged_seconds,
                'work_started_at' => $this->work_started_at?->toIso8601String(),
                'breach_risk' => $this->flagsContractualBreach(),
            ],
            'cost' => $this->cost()->toArray(),
            'suggested_price' => $this->suggestedPrice()?->toArray(),
            'margin' => $this->margin()?->toArray(),
            'margin_percent' => $this->marginPercent(),
        ];

        return $payload;
    }

    /**
     * Approval keeps its promise from FA-6 and FA-11: mint the next baseline
     * version. The engagement row is locked to serialize version numbers.
     * The approved version copies every item and role-mix line forward,
     * shifts the impacted milestone by the structured day count, appends the
     * change as a valued deliverable carrying the CR's role mix, and grows
     * the contract value by the approved price — deliverable values and cost
     * budget stay consistent with the ledger.
     */
    protected function mintBaselineVersion(): Baseline
    {
        Engagement::query()->whereKey($this->engagement_id)->lockForUpdate()->first();

        $current = $this->engagement->approvedBaseline();

        if ($current === null || $this->customer_price === null) {
            throw new LogicException('Approval requires an approved baseline and a customer price to version from.');
        }

        $current->load(['items', 'allocations']);

        $next = new Baseline([
            'commercial_model' => $current->commercial_model,
            'contract_value' => $current->contract_value->add($this->customer_price),
            'start_date' => $current->start_date,
            'end_date' => $current->end_date,
            'execution_mode' => $current->execution_mode,
        ]);
        $next->organization_id = $this->organization_id;
        $next->engagement_id = $this->engagement_id;
        $next->version = (int) $this->engagement->baselines()->max('version') + 1;
        $next->rate_card_version_id = $this->rate_card_version_id ?? $current->rate_card_version_id;
        $next->save();

        $impactedItemId = $this->resolveImpactMilestoneId($current);
        $itemMap = [];

        foreach ($current->items as $item) {
            $baselineDate = $item->baseline_date;

            if ($item->id === $impactedItemId && $baselineDate !== null && $this->impact_days !== null) {
                $baselineDate = $baselineDate->copy()->addDays($this->impact_days);
            }

            $copy = $next->items()->create([
                'organization_id' => $this->organization_id,
                'type' => $item->type,
                'position' => $item->position,
                'title' => $item->title,
                'description' => $item->description,
                'clause_reference' => $item->clause_reference,
                'owner_id' => $item->owner_id,
                'value' => $item->value,
                'acceptance_criteria' => $item->acceptance_criteria,
                'baseline_date' => $baselineDate,
                'payment_trigger' => $item->payment_trigger,
                'source_item_id' => $item->id,
            ]);

            $itemMap[$item->id] = $copy->id;
        }

        $deliverable = $next->items()->create([
            'organization_id' => $this->organization_id,
            'type' => BaselineItemType::Deliverable,
            'position' => (int) $current->items->where('type', BaselineItemType::Deliverable)->max('position') + 1,
            'title' => $this->title,
            'description' => $this->scope_added ?? $this->what,
            'clause_reference' => __('Change request — approved :date', ['date' => now()->toDateString()]),
            'owner_id' => $this->created_by,
            'value' => $this->customer_price,
        ]);

        foreach ($current->allocations as $allocation) {
            $next->allocations()->create([
                'organization_id' => $this->organization_id,
                'baseline_item_id' => $allocation->baseline_item_id === null ? null : $itemMap[$allocation->baseline_item_id],
                'rate_card_role_id' => $allocation->rate_card_role_id,
                'days' => $allocation->days,
            ]);
        }

        foreach ($this->allocations as $line) {
            $next->allocations()->create([
                'organization_id' => $this->organization_id,
                'baseline_item_id' => $deliverable->id,
                'rate_card_role_id' => $line->rate_card_role_id,
                'days' => $line->days,
            ]);
        }

        /*
         * The ledger versioned underneath execution: deliverable acceptance
         * records and work-item mappings follow their items onto the new
         * version's rows, and the appended change-request deliverable gets
         * its own record (FA-22, FA-23).
         */
        Deliverable::repointToMintedItems($itemMap);

        foreach (WorkItemLink::query()->whereIn('baseline_item_id', array_keys($itemMap))->get() as $link) {
            $link->baseline_item_id = $itemMap[$link->baseline_item_id];
            $link->save();
        }

        $next->status = BaselineStatus::Approved;
        $next->approved_at = now();
        $next->save();

        Deliverable::provisionForBaseline($next);

        AuditLog::record('baseline.version_minted', $next, [
            'version' => $next->version,
            'previous_version' => $current->version,
            'change_request_id' => $this->id,
            'change_request' => $this->title,
            'contract_value' => $next->contract_value->format(),
            'schedule_impact' => $this->impact_milestone_id === null ? null : [
                'milestone' => $impactedItemId === null ? null : $current->items->firstWhere('id', $impactedItemId)?->title,
                'days' => $this->impact_days,
                'applied' => $impactedItemId !== null && $this->impact_days !== null,
            ],
        ]);

        /*
         * A drift-born change that is approved is scope now: map its origin
         * work item to the deliverable the approval created, so the drift
         * loop closes on the ledger too.
         */
        $this->workItem?->absorbIntoApprovedScope($deliverable);

        return $next;
    }

    /**
     * Resolve the assessed schedule impact onto the baseline being copied.
     * The assessment references a milestone on the version that was current
     * back then — another change request approved in the meantime may have
     * minted newer versions since. Copies carry source-item lineage, so the
     * reference is rebased by walking each current item's ancestry back to
     * the assessed milestone. The impact itself is a day count, so shifts
     * from concurrently approved changes compose instead of clashing.
     */
    private function resolveImpactMilestoneId(Baseline $current): ?string
    {
        if ($this->impact_milestone_id === null) {
            return null;
        }

        if ($current->items->contains('id', $this->impact_milestone_id)) {
            return $this->impact_milestone_id;
        }

        foreach ($current->items as $item) {
            $ancestor = $item->sourceItem;

            while ($ancestor !== null) {
                if ($ancestor->id === $this->impact_milestone_id) {
                    return $item->id;
                }

                $ancestor = $ancestor->sourceItem;
            }
        }

        return null;
    }

    protected function applyApproval(Stakeholder $stakeholder, ?string $comment): void
    {
        $baseline = $this->mintBaselineVersion();

        $this->status = ChangeRequestStatus::Approved;
        $this->decided_at = now();
        $this->minted_baseline_id = $baseline->id;
        $this->save();

        AuditLog::record('change_request.approved', $this, [
            'decided_by' => $stakeholder->name,
            'comment' => $comment,
            'price' => $this->customer_price?->format(),
            'baseline_version' => $baseline->version,
        ]);
    }

    protected function applyRejection(Stakeholder $stakeholder, ?string $comment): void
    {
        $this->status = ChangeRequestStatus::Rejected;
        $this->decided_at = now();
        $this->save();

        AuditLog::record('change_request.rejected', $this, [
            'decided_by' => $stakeholder->name,
            'comment' => $comment,
        ]);
    }

    /**
     * A clarification request reopens the assessment; the frozen snapshots
     * and the response stay on record, and resubmission freezes fresh ones.
     */
    protected function applyClarification(Stakeholder $stakeholder, ?string $comment): void
    {
        $this->status = ChangeRequestStatus::UnderAssessment;
        $this->save();

        AuditLog::record('change_request.clarification_requested', $this, [
            'requested_by' => $stakeholder->name,
            'comment' => $comment,
        ]);
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /**
     * @return BelongsTo<WorkItem, $this>
     */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
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
     * @return BelongsTo<BaselineItem, $this>
     */
    public function impactMilestone(): BelongsTo
    {
        return $this->belongsTo(BaselineItem::class, 'impact_milestone_id');
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
     * @return BelongsTo<Baseline, $this>
     */
    public function mintedBaseline(): BelongsTo
    {
        return $this->belongsTo(Baseline::class, 'minted_baseline_id');
    }

    /**
     * @return HasMany<ChangeRequestAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ChangeRequestAllocation::class);
    }

    /**
     * @return BelongsToMany<BaselineItem, $this>
     */
    public function affectedItems(): BelongsToMany
    {
        return $this->belongsToMany(BaselineItem::class, 'change_request_affected_items')
            ->withTimestamps();
    }

    /**
     * @return HasMany<ChangeRequestResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(ChangeRequestResponse::class)->latest('created_at');
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
            'status' => ChangeRequestStatus::class,
            'origin' => ChangeRequestOrigin::class,
            'estimated_days' => 'float',
            'logged_seconds' => 'integer',
            'customer_price' => Money::class,
            'impact_days' => 'integer',
            'work_started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'respond_by' => 'datetime',
            'last_reminded_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
