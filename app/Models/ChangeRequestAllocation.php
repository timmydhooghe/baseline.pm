<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use App\ValueObjects\Money;
use Database\Factories\ChangeRequestAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A role-mix line on a change request's effort assessment (FA-12): estimated
 * days for one rate card role, priced at the version pinned on the change
 * request. Internal cost derives from the cost rate and the suggested
 * customer price from the sell rate — neither is ever typed. Lines can only
 * change while the assessment is open.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $change_request_id
 * @property string $rate_card_role_id
 * @property string $days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read ChangeRequest $changeRequest
 * @property-read RateCardRole $role
 */
#[Fillable(['organization_id', 'rate_card_role_id', 'days'])]
class ChangeRequestAllocation extends Model
{
    /** @use HasFactory<ChangeRequestAllocationFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, RecordsAuditLog;

    protected static function booted(): void
    {
        $guard = function (ChangeRequestAllocation $allocation): void {
            if (! $allocation->changeRequest->status->acceptsAssessment()) {
                throw new LogicException('Role-mix lines can only change while the change request is being assessed.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * The derived internal cost of this line: estimated days times the
     * pinned cost rate, rounded to whole cents.
     */
    public function cost(): Money
    {
        return Money::fromCents((int) round((float) $this->days * $this->role->cost_per_day->amount));
    }

    /**
     * The line's share of the suggested customer price: estimated days times
     * the pinned sell rate — the rate card's target margin applied to cost.
     */
    public function suggestedPrice(): Money
    {
        return Money::fromCents((int) round((float) $this->days * $this->role->sell_per_day->amount));
    }

    /**
     * @return BelongsTo<ChangeRequest, $this>
     */
    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
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
