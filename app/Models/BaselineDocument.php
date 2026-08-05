<?php

namespace App\Models;

use App\Enums\BaselineStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use Database\Factories\BaselineDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * A contract file on a baseline (FA-5 step 2): SOW, proposal or annex.
 * Stored on the private local disk — internal-only until baseline approval
 * shares it through the portal. Attachments follow the baseline's
 * immutability: once submitted, the contract set is frozen.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $baseline_id
 * @property string $filename
 * @property string $path
 * @property string|null $mime_type
 * @property int $size_bytes
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Baseline $baseline
 * @property-read User|null $uploadedBy
 */
#[Fillable(['organization_id', 'filename', 'path', 'mime_type', 'size_bytes', 'uploaded_by'])]
class BaselineDocument extends Model
{
    /** @use HasFactory<BaselineDocumentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, RecordsAuditLog;

    protected static function booted(): void
    {
        $guard = function (BaselineDocument $document): void {
            if ($document->baseline->status !== BaselineStatus::Draft) {
                throw new LogicException('Contract documents can only change while the baseline is a draft.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);

        static::deleted(function (BaselineDocument $document): void {
            Storage::disk('local')->delete($document->path);
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
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
