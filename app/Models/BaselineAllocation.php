<?php

namespace App\Models;

use App\Enums\BaselineStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use App\ValueObjects\Money;
use Database\Factories\BaselineAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A role-mix line on a baseline's cost budget (FA-5 step 4): estimated days
 * for one rate card role, priced at the baseline's pinned rate card version.
 * Cost is always derived from the pinned rate — never typed — and lines
 * without an item carry the delivery-management effort. Internal only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $baseline_id
 * @property string|null $baseline_item_id
 * @property string $rate_card_role_id
 * @property string $days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Baseline $baseline
 * @property-read BaselineItem|null $item
 * @property-read RateCardRole $role
 */
#[Fillable(['organization_id', 'baseline_item_id', 'rate_card_role_id', 'days'])]
class BaselineAllocation extends Model
{
    /** @use HasFactory<BaselineAllocationFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, RecordsAuditLog;

    protected static function booted(): void
    {
        $guard = function (BaselineAllocation $allocation): void {
            if ($allocation->baseline->status !== BaselineStatus::Draft) {
                throw new LogicException('Role-mix allocations can only change while the baseline is a draft.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * The derived cost of this line: estimated days times the pinned cost
     * rate, rounded to whole cents.
     */
    public function cost(): Money
    {
        return Money::fromCents((int) round((float) $this->days * $this->role->cost_per_day->amount));
    }

    /**
     * @return BelongsTo<Baseline, $this>
     */
    public function baseline(): BelongsTo
    {
        return $this->belongsTo(Baseline::class);
    }

    /**
     * @return BelongsTo<BaselineItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BaselineItem::class, 'baseline_item_id');
    }

    /**
     * @return BelongsTo<RateCardRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(RateCardRole::class, 'rate_card_role_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'decimal:2',
        ];
    }
}
