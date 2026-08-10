<?php

namespace App\Models;

use App\Enums\BaselineDecision;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BaselineResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A customer's decision on a submitted baseline (FA-5 step 6, FA-27), tied
 * to the frozen customer snapshot it was made against. Responses are legally
 * meaningful and therefore append-only: updates and deletes are refused, and
 * the stakeholder's name is denormalized so the record survives them.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $baseline_id
 * @property string $snapshot_id
 * @property string|null $stakeholder_id
 * @property string $stakeholder_name
 * @property BaselineDecision $decision
 * @property string|null $comment
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read Baseline $baseline
 * @property-read Snapshot $snapshot
 * @property-read Stakeholder|null $stakeholder
 */
#[Fillable(['snapshot_id', 'stakeholder_id', 'stakeholder_name', 'decision', 'comment'])]
class BaselineResponse extends Model
{
    /** @use HasFactory<BaselineResponseFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Baseline responses are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Baseline responses are immutable and cannot be deleted.');
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
            'decision' => BaselineDecision::class,
            'created_at' => 'datetime',
        ];
    }
}
