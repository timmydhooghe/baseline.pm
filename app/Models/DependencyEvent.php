<?php

namespace App\Models;

use App\Enums\DependencyEventType;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in a dependency's evidence trail (FA-20): a request, a reminder,
 * an escalation, or the moment the item arrived or was waived. Append-only —
 * an attribution that can be rewritten afterwards proves nothing when a
 * milestone slip has to be defended.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $dependency_id
 * @property DependencyEventType $type
 * @property string|null $channel
 * @property string|null $note
 * @property string|null $evidence_url
 * @property string|null $actor_id
 * @property CarbonImmutable $occurred_at
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read Dependency $dependency
 * @property-read User|null $actor
 */
#[Fillable([
    'organization_id', 'type', 'channel', 'note', 'evidence_url', 'actor_id', 'occurred_at',
])]
class DependencyEvent extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('The dependency evidence trail is append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('The dependency evidence trail is append-only and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Dependency, $this>
     */
    public function dependency(): BelongsTo
    {
        return $this->belongsTo(Dependency::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DependencyEventType::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
