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
 * A record a decision touches (FA-18): the deliverable it scoped out, the
 * change request it authorised, the risk it accepted. Linked records rather
 * than prose, so the ledger reads from both ends.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $decision_id
 * @property string $linked_type
 * @property string $linked_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Decision $decision
 * @property-read Model|null $linked
 */
#[Fillable(['organization_id', 'linked_type', 'linked_id'])]
class DecisionLink extends Model
{
    use BelongsToOrganization, DescribesLinkedRecord, HasUuids;

    public function linkedRecord(): ?Model
    {
        return $this->linked;
    }

    public function linkedRecordType(): string
    {
        return $this->linked_type;
    }

    public function linkedRecordId(): string
    {
        return $this->linked_id;
    }

    /**
     * @return BelongsTo<Decision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function linked(): MorphTo
    {
        return $this->morphTo();
    }
}
