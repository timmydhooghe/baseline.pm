<?php

namespace App\Models;

use App\Enums\BaselineItemType;
use App\Enums\BaselineStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use App\ValueObjects\Money;
use Database\Factories\BaselineItemFactory;
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
 * A typed contract item on a baseline (FA-5 step 3): deliverable, milestone,
 * assumption, exclusion or customer responsibility, each traced to a contract
 * clause. Items follow their baseline's immutability: they can only change
 * while the baseline is a draft.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $baseline_id
 * @property BaselineItemType $type
 * @property int $position
 * @property string $title
 * @property string|null $description
 * @property string $clause_reference
 * @property string|null $owner_id
 * @property Money|null $value
 * @property list<array{criterion: string, verification_method: string|null}>|null $acceptance_criteria
 * @property Carbon|null $baseline_date
 * @property string|null $payment_trigger
 * @property string|null $source_item_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Baseline $baseline
 * @property-read User|null $owner
 * @property-read BaselineItem|null $sourceItem
 * @property-read Collection<int, BaselineAllocation> $allocations
 */
#[Fillable(['organization_id', 'type', 'position', 'title', 'description', 'clause_reference', 'owner_id', 'value', 'acceptance_criteria', 'baseline_date', 'payment_trigger', 'source_item_id'])]
class BaselineItem extends Model
{
    /** @use HasFactory<BaselineItemFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, RecordsAuditLog;

    protected static function booted(): void
    {
        $guard = function (BaselineItem $item): void {
            if ($item->baseline->status !== BaselineStatus::Draft) {
                throw new LogicException('Baseline items can only change while the baseline is a draft.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);

        /*
         * Without this, the DB cascade would silently drop work mappings —
         * no audit trail — and leave existing-scope classifications
         * orphaned: unmapped work the triage inbox would never surface
         * again. Unlinking through the model keeps the removal audited and
         * resets the classification, so the work re-enters drift (FA-9).
         */
        static::deleting(function (BaselineItem $item): void {
            WorkItemLink::query()
                ->where('baseline_item_id', $item->id)
                ->with('workItem')
                ->get()
                ->each(fn (WorkItemLink $link) => $link->workItem->unlink());
        });
    }

    /**
     * @return BelongsTo<Baseline, $this>
     */
    public function baseline(): BelongsTo
    {
        return $this->belongsTo(Baseline::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The item this one was copied from when its baseline version was
     * minted — the lineage that lets references held against an older
     * version rebase onto the current one (FA-6).
     *
     * @return BelongsTo<BaselineItem, $this>
     */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_item_id');
    }

    /**
     * @return HasMany<BaselineAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(BaselineAllocation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BaselineItemType::class,
            'value' => Money::class,
            'acceptance_criteria' => 'array',
            'baseline_date' => 'date',
        ];
    }
}
