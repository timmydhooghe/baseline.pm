<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One step in a deliverable record's baseline trail (FA-22's version
 * history): the item row the record pointed at in a given baseline version.
 * Written at provisioning and at every minted version, never edited.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $deliverable_id
 * @property string $baseline_item_id
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read Deliverable $deliverable
 * @property-read BaselineItem $baselineItem
 */
#[Fillable(['organization_id', 'baseline_item_id'])]
class DeliverableVersion extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A deliverable version trail is append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('A deliverable version trail is append-only.');
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
     * @return BelongsTo<BaselineItem, $this>
     */
    public function baselineItem(): BelongsTo
    {
        return $this->belongsTo(BaselineItem::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
