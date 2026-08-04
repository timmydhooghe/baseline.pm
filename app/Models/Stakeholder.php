<?php

namespace App\Models;

use App\Enums\StakeholderRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use Database\Factories\StakeholderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * External customer-side contact who accesses the portal via the `stakeholder`
 * guard (magic-link / signed-URL login — no password). Stakeholders never
 * consume paid seats; they belong to a customer record with a portal role.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_id
 * @property StakeholderRole $role
 * @property string $name
 * @property string $email
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Customer $customer
 */
#[Fillable(['name', 'email', 'role'])]
#[Hidden(['remember_token'])]
class Stakeholder extends Authenticatable
{
    /** @use HasFactory<StakeholderFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, Notifiable, RecordsAuditLog;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'viewer',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => StakeholderRole::class,
        ];
    }
}
