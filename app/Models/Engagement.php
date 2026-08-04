<?php

namespace App\Models;

use App\Enums\EngagementStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use Database\Factories\EngagementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
