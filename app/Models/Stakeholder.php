<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditLog;
use Database\Factories\StakeholderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * External customer-side contact who accesses the portal via the `stakeholder`
 * guard (magic-link / signed-URL login — no password). Scaffold only; the full
 * customer/stakeholder domain lands with WEBAPP-16.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email'])]
#[Hidden(['remember_token'])]
class Stakeholder extends Authenticatable
{
    /** @use HasFactory<StakeholderFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, Notifiable, RecordsAuditLog;
}
