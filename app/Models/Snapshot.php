<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Immutable point-in-time snapshot of a subject's state (baselines, change
 * requests, reports, burn weeks). The payload is frozen at capture time and
 * fingerprinted so tampering is detectable. Updates and deletes are refused.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $subject_type
 * @property string $subject_id
 * @property array<string, mixed> $payload
 * @property string $hash
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read User|null $creator
 */
#[Fillable(['organization_id', 'subject_type', 'subject_id', 'payload', 'hash', 'created_by'])]
class Snapshot extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Snapshots are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Snapshots are immutable and cannot be deleted.');
        });
    }

    /**
     * Freeze the given payload as an immutable snapshot of the subject.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function capture(Model $subject, array $payload, ?User $creator = null): self
    {
        $creator ??= Auth::guard('web')->user();

        $organizationId = AuditLog::resolveOrganizationId($subject);

        if ($organizationId === null) {
            throw new LogicException('A snapshot requires an organization: none could be resolved for ['.$subject::class.'].');
        }

        return self::query()->create([
            'organization_id' => $organizationId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'payload' => $payload,
            'hash' => self::hashPayload($payload),
            'created_by' => $creator?->getKey(),
        ]);
    }

    /**
     * Compute the canonical SHA-256 fingerprint of a payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hashPayload(array $payload): string
    {
        $json = json_encode(
            self::canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $json);
    }

    /**
     * Whether the stored payload still matches its recorded hash.
     */
    public function verifyIntegrity(): bool
    {
        return hash_equals($this->hash, self::hashPayload($this->payload));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Sort associative keys recursively so hashing is order-independent.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
