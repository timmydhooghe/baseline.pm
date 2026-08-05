<?php

namespace App\Models;

use App\Enums\EngagementStatus;
use App\Enums\Plan;
use App\Models\Concerns\RecordsAuditLog;
use App\ValueObjects\Money;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 * @property-read Collection<int, RateCardVersion> $rateCardVersions
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
     * @return HasMany<RateCardVersion, $this>
     */
    public function rateCardVersions(): HasMany
    {
        return $this->hasMany(RateCardVersion::class);
    }

    /**
     * The rate card version new baselines are priced with.
     */
    public function currentRateCardVersion(): ?RateCardVersion
    {
        return $this->rateCardVersions()->orderByDesc('version')->first();
    }

    /**
     * Publish the next rate card version as a complete snapshot of role rates.
     *
     * @param  list<array{name: string, cost_per_day: Money, sell_per_day: Money}>  $roles
     */
    public function publishRateCardVersion(array $roles, ?User $publishedBy = null): RateCardVersion
    {
        return DB::transaction(function () use ($roles, $publishedBy): RateCardVersion {
            /*
             * PostgreSQL refuses FOR UPDATE on aggregate queries, so concurrent
             * publishes are serialized by locking the organization row instead
             * of the MAX(version) read itself.
             */
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $latestVersion = (int) $this->rateCardVersions()->max('version');

            $version = $this->rateCardVersions()->create([
                'version' => $latestVersion + 1,
                'created_by' => $publishedBy?->id,
            ]);

            foreach ($roles as $position => $role) {
                $version->roles()->create([
                    'organization_id' => $this->id,
                    'name' => $role['name'],
                    'cost_per_day' => $role['cost_per_day'],
                    'sell_per_day' => $role['sell_per_day'],
                    'position' => $position,
                ]);
            }

            return $version;
        });
    }

    /**
     * Start a draft engagement, rechecking the plan limit under a lock so
     * concurrent requests cannot both claim the last plan slot.
     */
    public function startEngagement(string $name, string $customerId): Engagement
    {
        return DB::transaction(function () use ($name, $customerId): Engagement {
            /*
             * PostgreSQL refuses FOR UPDATE on aggregate queries, so the
             * count is serialized by locking the organization row instead.
             */
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            if ($this->hasReachedActiveEngagementLimit()) {
                throw ValidationException::withMessages([
                    'plan' => $this->activeEngagementLimitMessage(),
                ]);
            }

            $engagement = new Engagement(['name' => $name]);
            $engagement->organization_id = $this->id;
            $engagement->customer_id = $customerId;
            $engagement->save();

            return $engagement;
        });
    }

    /**
     * The error shown when the plan's active-engagement limit is reached.
     */
    public function activeEngagementLimitMessage(): string
    {
        return __('The :plan plan is limited to :limit active engagements. Archive an engagement or upgrade your plan to start a new one.', [
            'plan' => $this->plan->label(),
            'limit' => (string) $this->plan->activeEngagementLimit(),
        ]);
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
