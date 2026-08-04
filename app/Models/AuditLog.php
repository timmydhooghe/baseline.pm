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
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * Append-only audit trail entry. Updates and deletes are refused at the model level.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property string $subject_id
 * @property array<string, mixed>|null $payload
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read User|null $actor
 */
#[Fillable(['organization_id', 'actor_id', 'action', 'subject_type', 'subject_id', 'payload'])]
class AuditLog extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit logs are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit logs are append-only and cannot be deleted.');
        });
    }

    /**
     * Append an audit log entry for the given subject.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function record(
        string $action,
        Model $subject,
        ?array $payload = null,
        ?User $actor = null,
        ?string $organizationId = null,
    ): self {
        $actor ??= Auth::guard('web')->user();

        $organizationId ??= self::resolveOrganizationId($subject);

        if ($organizationId === null) {
            throw new LogicException('An audit log entry requires an organization: none could be resolved for ['.$subject::class.'].');
        }

        return self::query()->create([
            'organization_id' => $organizationId,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'payload' => $payload,
        ]);
    }

    /**
     * Resolve the organization a subject belongs to, falling back to the current context.
     */
    public static function resolveOrganizationId(Model $subject): ?string
    {
        if ($subject instanceof Organization) {
            return $subject->getKey();
        }

        $organizationId = $subject->getAttribute('organization_id') ?? Context::get('organization_id');

        return is_string($organizationId) ? $organizationId : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
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
