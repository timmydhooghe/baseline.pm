<?php

namespace App\Models;

use App\Enums\EvidenceKind;
use App\Enums\RecordVisibility;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A piece of evidence on a deliverable record (FA-22): a release, demo,
 * test report or document backing progress and acceptance criteria. Each
 * item carries its own visibility — internal evidence never reaches a
 * customer snapshot. The list follows the record's freeze: once the
 * deliverable is submitted or accepted, the evidence behind it stops moving.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $deliverable_id
 * @property EvidenceKind $kind
 * @property string $label
 * @property string|null $url
 * @property RecordVisibility $visibility
 * @property string|null $added_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Deliverable $deliverable
 * @property-read User|null $addedBy
 */
#[Fillable(['organization_id', 'kind', 'label', 'url', 'visibility', 'added_by'])]
class DeliverableEvidence extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'deliverable_evidence';

    protected static function booted(): void
    {
        $guard = function (DeliverableEvidence $evidence): void {
            if (! $evidence->deliverable->status->acceptsUpdates()) {
                throw new LogicException('Evidence can only change while the deliverable record is open for editing.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * @return BelongsTo<Deliverable, $this>
     */
    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EvidenceKind::class,
            'visibility' => RecordVisibility::class,
        ];
    }
}
