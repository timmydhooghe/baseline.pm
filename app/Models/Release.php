<?php

namespace App\Models;

use App\Enums\WorkItemSource;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A release synced from a provider (FA-7): a Jira project version or a
 * Linear release. Future evidence material for deliverable acceptance
 * (FA-22).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property string|null $integration_connection_id
 * @property WorkItemSource $source
 * @property string|null $external_id
 * @property string $name
 * @property bool $released
 * @property Carbon|null $released_on
 * @property string|null $external_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read IntegrationConnection|null $integration
 */
#[Fillable(['source', 'external_id', 'name', 'released', 'released_on', 'external_url'])]
class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /**
     * Named integration, not connection — Eloquent already owns a
     * $connection property (the database connection name).
     *
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => WorkItemSource::class,
            'released' => 'boolean',
            'released_on' => 'date',
        ];
    }
}
