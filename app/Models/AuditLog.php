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
 * Append-only audit trail entry (FA-21). Updates and deletes are refused at
 * the model level. Every entry names the engagement it belongs to where one
 * can be resolved, so the trail can be read from the engagement it governs
 * as well as from the record it is about.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $engagement_id
 * @property string|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property string $subject_id
 * @property array<string, mixed>|null $payload
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read Engagement|null $engagement
 * @property-read User|null $actor
 */
#[Fillable(['organization_id', 'engagement_id', 'actor_id', 'action', 'subject_type', 'subject_id', 'payload'])]
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
            'engagement_id' => self::resolveEngagementId($subject),
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
     * The engagement whose trail this entry belongs to. Records that carry
     * the engagement themselves answer directly; records that hang off one
     * (a baseline item, a role-mix line) inherit it from the parent they
     * belong to. Organization-level subjects — customers, rate cards,
     * invitations — legitimately belong to no engagement.
     */
    public static function resolveEngagementId(Model $subject): ?string
    {
        if ($subject instanceof Engagement) {
            /*
             * The entry recording an engagement's own deletion cannot point
             * at it: the row is already gone and the reference would be
             * rejected. It belongs to no engagement, which is exactly what
             * a null says.
             */
            return $subject->exists ? $subject->getKey() : null;
        }

        $engagementId = $subject->getAttribute('engagement_id');

        if (is_string($engagementId)) {
            return $engagementId;
        }

        foreach (['baseline', 'changeRequest', 'deliverable', 'risk', 'dependency', 'decision'] as $relation) {
            if (! $subject->isRelation($relation)) {
                continue;
            }

            $parent = $subject->getAttribute($relation);

            return $parent instanceof Model ? self::resolveEngagementId($parent) : null;
        }

        return null;
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
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
