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
 * What a risk threatens (FA-19): a deliverable, a milestone, a change
 * request or a dependency, always as a linked record so the threat is
 * visible from the threatened side too.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $risk_id
 * @property string $threatened_type
 * @property string $threatened_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Risk $risk
 * @property-read Model|null $threatened
 */
#[Fillable(['organization_id', 'threatened_type', 'threatened_id'])]
class RiskLink extends Model
{
    use BelongsToOrganization, DescribesLinkedRecord, HasUuids;

    public function linkedRecord(): ?Model
    {
        return $this->threatened;
    }

    public function linkedRecordType(): string
    {
        return $this->threatened_type;
    }

    public function linkedRecordId(): string
    {
        return $this->threatened_id;
    }

    /**
     * @return BelongsTo<Risk, $this>
     */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function threatened(): MorphTo
    {
        return $this->morphTo();
    }
}
