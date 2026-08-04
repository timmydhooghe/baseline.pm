<?php

namespace App\Models;

use App\Enums\EngagementStatus;
use App\Enums\Plan;
use App\Models\Concerns\RecordsAuditLog;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The tenant root. All tenant data hangs off an organization via organization_id.
 *
 * @property string $id
 * @property string $name
 * @property Plan $plan
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Customer> $customers
 * @property-read Collection<int, Stakeholder> $stakeholders
 * @property-read Collection<int, Engagement> $engagements
 * @property-read Collection<int, Invitation> $invitations
 */
#[Fillable(['name'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids, RecordsAuditLog;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'plan' => 'solo',
    ];

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<Stakeholder, $this>
     */
    public function stakeholders(): HasMany
    {
        return $this->hasMany(Stakeholder::class);
    }

    /**
     * @return HasMany<Engagement, $this>
     */
    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * How many engagements currently occupy a plan slot (archived ones don't).
     */
    public function activeEngagementCount(): int
    {
        return $this->engagements()
            ->whereNot('status', EngagementStatus::Archived)
            ->count();
    }

    public function hasReachedActiveEngagementLimit(): bool
    {
        $limit = $this->plan->activeEngagementLimit();

        return $limit !== null && $this->activeEngagementCount() >= $limit;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => Plan::class,
        ];
    }
}
