<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\DescribesLinkedRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A record a dependency blocks (FA-20): the deliverable that cannot start or
 * the milestone that moves day for day while the item stays outstanding.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $dependency_id
 * @property string $affected_type
 * @property string $affected_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Dependency $dependency
 * @property-read Model|null $affected
 */
#[Fillable(['organization_id', 'affected_type', 'affected_id'])]
class DependencyLink extends Model
{
    use BelongsToOrganization, DescribesLinkedRecord, HasUuids;

    public function linkedRecord(): ?Model
    {
        return $this->affected;
    }

    public function linkedRecordType(): string
    {
        return $this->affected_type;
    }

    public function linkedRecordId(): string
    {
        return $this->affected_id;
    }

    /**
     * @return BelongsTo<Dependency, $this>
     */
    public function dependency(): BelongsTo
    {
        return $this->belongsTo(Dependency::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function affected(): MorphTo
    {
        return $this->morphTo();
    }
}
