<?php

namespace App\Models;

use App\Enums\AcceptanceDecision;
use App\Enums\EngagementStatus;
use App\Enums\FinalAcceptanceStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * An engagement-level final acceptance request (FA-24): the signed gate
 * before Completed. Submitted once every deliverable is accepted, it freezes
 * twin snapshots of the accepted record and awaits the customer's signature
 * in the portal. Acceptance completes the engagement; rejection and
 * clarification return it to Active; withdrawal closes the request
 * internally. The decision is stored inline and the record becomes
 * immutable once closed — a resubmission is a new record.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property FinalAcceptanceStatus $status
 * @property string|null $review_snapshot_id
 * @property string|null $customer_snapshot_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $respond_by
 * @property CarbonImmutable|null $decided_at
 * @property AcceptanceDecision|null $decision
 * @property string|null $stakeholder_id
 * @property string|null $stakeholder_name
 * @property string|null $comment
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read Snapshot|null $reviewSnapshot
 * @property-read Snapshot|null $customerSnapshot
 * @property-read Stakeholder|null $stakeholder
 * @property-read User|null $createdBy
 */
#[Fillable(['respond_by', 'submitted_at', 'created_by'])]
class FinalAcceptance extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'awaiting_response',
    ];

    protected static function booted(): void
    {
        static::updating(function (FinalAcceptance $finalAcceptance): void {
            $original = FinalAcceptanceStatus::from((string) $finalAcceptance->getRawOriginal('status'));

            if (! $original->isOpen()) {
                throw new LogicException('A closed final acceptance is immutable — a resubmission is a new record.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Final acceptance requests are governance history and cannot be deleted.');
        });
    }

    /**
     * Record the customer's decision on the frozen record (FA-24).
     * Acceptance is the signature that completes the engagement; rejection
     * and clarification return it to Active for rework and a fresh
     * submission.
     */
    public function recordResponse(Stakeholder $stakeholder, AcceptanceDecision $decision, ?string $comment = null): void
    {
        if (! $stakeholder->role->canApprove()) {
            throw ValidationException::withMessages([
                'decision' => __('Only stakeholders with approval rights can respond to a final acceptance.'),
            ]);
        }

        DB::transaction(function () use ($stakeholder, $decision, $comment): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if ($stakeholder->customer_id !== $this->engagement->customer_id) {
                throw new LogicException('This stakeholder does not belong to the engagement customer.');
            }

            if (! $this->status->isOpen() || $this->customer_snapshot_id === null) {
                throw ValidationException::withMessages([
                    'decision' => __('This engagement is no longer awaiting final acceptance.'),
                ]);
            }

            $this->status = match ($decision) {
                AcceptanceDecision::Accepted => FinalAcceptanceStatus::Accepted,
                AcceptanceDecision::Rejected => FinalAcceptanceStatus::Rejected,
                AcceptanceDecision::ClarificationRequested => FinalAcceptanceStatus::ClarificationRequested,
            };
            $this->decision = $decision;
            $this->decided_at = now();
            $this->stakeholder_id = $stakeholder->id;
            $this->stakeholder_name = $stakeholder->name;
            $this->comment = $comment;
            $this->save();

            AuditLog::record(match ($decision) {
                AcceptanceDecision::Accepted => 'final_acceptance.accepted',
                AcceptanceDecision::Rejected => 'final_acceptance.rejected',
                AcceptanceDecision::ClarificationRequested => 'final_acceptance.clarification_requested',
            }, $this, [
                'decided_by' => $stakeholder->name,
                'comment' => $comment,
            ]);

            $this->engagement->transitionTo($decision === AcceptanceDecision::Accepted
                ? EngagementStatus::Completed
                : EngagementStatus::Active);
        });
    }

    /**
     * Close an open request internally — the engagement was moved back to
     * Active by hand. The frozen snapshots stay on record.
     */
    public function withdraw(): void
    {
        if (! $this->status->isOpen()) {
            throw new LogicException('Only an open final acceptance can be withdrawn.');
        }

        $this->status = FinalAcceptanceStatus::Withdrawn;
        $this->save();

        AuditLog::record('final_acceptance.withdrawn', $this);
    }

    /**
     * The stakeholders who may sign the final acceptance: the engagement
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
     * The frozen payload for a final acceptance snapshot: the engagement,
     * its approved contract value and every signed deliverable acceptance.
     * Both variants carry customer-visible figures only — the internal twin
     * exists so each side's record is independently verifiable.
     *
     * @return array<string, mixed>
     */
    public function snapshotPayload(bool $internal): array
    {
        $engagement = $this->engagement;
        $baseline = $engagement->approvedBaseline();

        $deliverables = $engagement->deliverables()
            ->with(['baselineItem', 'responses'])
            ->get()
            ->sortBy(fn (Deliverable $deliverable): int => $deliverable->baselineItem->position)
            ->values();

        return [
            'kind' => $internal ? 'internal_review' : 'customer_review',
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'customer' => [
                    'id' => $engagement->customer->id,
                    'name' => $engagement->customer->name,
                ],
            ],
            'baseline_version' => $baseline?->version,
            'contract_value' => $baseline?->contract_value->toArray(),
            'accepted_value' => $engagement->acceptedValue()->toArray(),
            'deliverables' => $deliverables
                ->map(fn (Deliverable $deliverable): array => [
                    'id' => $deliverable->id,
                    'title' => $deliverable->baselineItem->title,
                    'value' => $deliverable->accepted_value?->toArray(),
                    'accepted_on' => $deliverable->accepted_at?->toDateString(),
                    'accepted_by' => $deliverable->responses
                        ->first(fn (DeliverableResponse $response): bool => $response->decision === AcceptanceDecision::Accepted)
                        ?->stakeholder_name,
                ])
                ->all(),
            'respond_by' => $this->respond_by?->toDateString(),
        ];
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
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
     * @return BelongsTo<Stakeholder, $this>
     */
    public function stakeholder(): BelongsTo
    {
        return $this->belongsTo(Stakeholder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
            'status' => FinalAcceptanceStatus::class,
            'decision' => AcceptanceDecision::class,
            'submitted_at' => 'datetime',
            'respond_by' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
