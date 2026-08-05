<?php

namespace App\Models;

use App\Enums\BaselineStatus;
use App\Enums\EngagementStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use Database\Factories\EngagementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
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
 * An engagement delivered for a customer. Its lifecycle is the
 * EngagementStatus state machine; once archived it is read-only (still
 * searchable) and no longer counts toward the plan's engagement limit.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_id
 * @property string $name
 * @property EngagementStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Customer $customer
 * @property-read Collection<int, Baseline> $baselines
 */
#[Fillable(['name'])]
class Engagement extends Model
{
    /** @use HasFactory<EngagementFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, RecordsAuditLog;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected static function booted(): void
    {
        static::updating(function (Engagement $engagement): void {
            if ($engagement->getRawOriginal('status') === EngagementStatus::Archived->value) {
                throw new LogicException('Archived engagements are read-only.');
            }
        });

        static::deleting(function (Engagement $engagement): void {
            if ($engagement->status === EngagementStatus::Archived) {
                throw new LogicException('Archived engagements are read-only.');
            }
        });
    }

    /**
     * Move the engagement to the next lifecycle status, refusing moves the
     * EngagementStatus state machine does not allow.
     */
    public function transitionTo(EngagementStatus $target): void
    {
        $from = $this->status;

        if (! $from->canTransitionTo($target)) {
            throw new LogicException("An engagement cannot move from [{$from->value}] to [{$target->value}].");
        }

        $this->status = $target;
        $this->save();

        AuditLog::record('engagement.transitioned', $this, [
            'from' => $from->value,
            'to' => $target->value,
        ]);

        $this->syncSubmittedBaseline($from, $target);
    }

    /**
     * Start the next baseline draft, pinning the organization's current rate
     * card version. Serialized under a lock so concurrent requests cannot
     * claim the same version number or open two drafts.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function startBaseline(array $attributes, ?User $author = null): Baseline
    {
        return DB::transaction(function () use ($attributes, $author): Baseline {
            /*
             * PostgreSQL refuses FOR UPDATE on aggregate queries, so the
             * version read is serialized by locking the engagement row.
             */
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            if (! in_array($this->status, [EngagementStatus::Draft, EngagementStatus::PreparingBaseline], true)) {
                throw ValidationException::withMessages([
                    'baseline' => __('A baseline can only be drafted while the engagement is preparing one. Later versions come from approved change requests.'),
                ]);
            }

            if ($this->baselines()->whereNot('status', BaselineStatus::Approved)->exists()) {
                throw ValidationException::withMessages([
                    'baseline' => __('This engagement already has a baseline in progress.'),
                ]);
            }

            $baseline = new Baseline([...$attributes, 'created_by' => $author?->id]);
            $baseline->organization_id = $this->organization_id;
            $baseline->engagement_id = $this->id;
            $baseline->version = (int) $this->baselines()->max('version') + 1;
            $baseline->rate_card_version_id = $this->organization->currentRateCardVersion()?->id;
            $baseline->save();

            if ($this->status === EngagementStatus::Draft) {
                $this->transitionTo(EngagementStatus::PreparingBaseline);
            }

            return $baseline;
        });
    }

    /**
     * The baseline currently being drafted or reviewed, if any.
     */
    public function openBaseline(): ?Baseline
    {
        return $this->baselines()
            ->whereNot('status', BaselineStatus::Approved)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * The approved baseline the engagement currently executes against.
     */
    public function approvedBaseline(): ?Baseline
    {
        return $this->baselines()
            ->where('status', BaselineStatus::Approved)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Baseline, $this>
     */
    public function baselines(): HasMany
    {
        return $this->hasMany(Baseline::class);
    }

    /**
     * Keep the submitted baseline in step when the engagement is moved
     * through the lifecycle directly: leaving awaiting-approval towards
     * Active approves it, moving back to preparing withdraws it. The
     * baseline's own methods flip its status before transitioning the
     * engagement, so this never runs twice for one decision.
     */
    protected function syncSubmittedBaseline(EngagementStatus $from, EngagementStatus $target): void
    {
        if ($from !== EngagementStatus::AwaitingBaselineApproval) {
            return;
        }

        $submitted = $this->baselines()
            ->where('status', BaselineStatus::AwaitingApproval)
            ->orderByDesc('version')
            ->first();

        if ($submitted === null) {
            return;
        }

        if ($target === EngagementStatus::Active) {
            $submitted->approve();
        } elseif ($target === EngagementStatus::PreparingBaseline) {
            $submitted->returnToDraft('withdrawn');
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EngagementStatus::class,
        ];
    }
}
