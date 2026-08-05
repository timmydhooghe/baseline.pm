<?php

namespace App\Models;

use App\Enums\ChangeRequestDecision;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ChangeRequestResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A customer's decision on a submitted change request (FA-13), tied to the
 * frozen customer snapshot it was made against. Responses are legally
 * meaningful and therefore append-only: updates and deletes are refused, and
 * the stakeholder's name is denormalized so the record survives them.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $change_request_id
 * @property string $snapshot_id
 * @property string|null $stakeholder_id
 * @property string $stakeholder_name
 * @property ChangeRequestDecision $decision
 * @property string|null $comment
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read ChangeRequest $changeRequest
 * @property-read Snapshot $snapshot
 * @property-read Stakeholder|null $stakeholder
 */
#[Fillable(['snapshot_id', 'stakeholder_id', 'stakeholder_name', 'decision', 'comment'])]
class ChangeRequestResponse extends Model
{
    /** @use HasFactory<ChangeRequestResponseFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Change request responses are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Change request responses are immutable and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<ChangeRequest, $this>
     */
    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
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
            'decision' => ChangeRequestDecision::class,
            'created_at' => 'datetime',
        ];
    }
}
