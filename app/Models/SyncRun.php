<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sync pass of an integration connection — the record behind FA-7's
 * always-visible sync status. Operational telemetry, not a governance
 * record, so it carries no audit trail of its own.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $integration_connection_id
 * @property SyncRunStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property array<string, int>|null $counts
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read IntegrationConnection $integration
 */
#[Fillable(['status', 'started_at'])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * Close the run as successful, recording what the pass upserted.
     *
     * @param  array<string, int>  $counts
     */
    public function markSucceeded(array $counts): void
    {
        $this->status = SyncRunStatus::Succeeded;
        $this->finished_at = now();
        $this->counts = $counts;
        $this->save();
    }

    /**
     * Close the run as failed, keeping the error visible on the work page.
     */
    public function markFailed(string $error): void
    {
        $this->status = SyncRunStatus::Failed;
        $this->finished_at = now();
        $this->error = $error;
        $this->save();
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
            'status' => SyncRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'counts' => 'array',
        ];
    }
}
