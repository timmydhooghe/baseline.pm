<?php

namespace App\Models;

use App\Enums\AcceptanceDecision;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A customer's immutable decision on a submitted deliverable (FA-23),
 * recorded against the frozen customer snapshot it was made on. The
 * stakeholder's name is denormalized so the record survives the
 * stakeholder; a response is never edited or deleted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $deliverable_id
 * @property string $snapshot_id
 * @property string|null $stakeholder_id
 * @property string $stakeholder_name
 * @property AcceptanceDecision $decision
 * @property string|null $comment
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read Deliverable $deliverable
 * @property-read Snapshot $snapshot
 * @property-read Stakeholder|null $stakeholder
 */
#[Fillable(['snapshot_id', 'stakeholder_id', 'stakeholder_name', 'decision', 'comment'])]
class DeliverableResponse extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Deliverable responses are immutable — a change of mind is a new record.');
        });

        static::deleting(function (): never {
            throw new LogicException('Deliverable responses are immutable and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Deliverable, $this>
     */
    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    /**
     * @return BelongsTo<Snapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    /**
     * @return BelongsTo<Stakeholder, $this>
     */
    public function stakeholder(): BelongsTo
    {
        return $this->belongsTo(Stakeholder::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => AcceptanceDecision::class,
            'created_at' => 'datetime',
        ];
    }
}
