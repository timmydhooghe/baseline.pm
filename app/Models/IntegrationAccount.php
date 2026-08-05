<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Integrations\JiraClient;
use App\Integrations\LinearClient;
use App\Integrations\ProviderClient;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\IntegrationAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * An organization-level provider account (FA-7): the named credential set a
 * Jira site or Linear workspace is reached with. Only the organization owner
 * manages accounts; engagements connect by picking one, so a key is entered
 * once and reused. Credentials are encrypted at rest and hidden, so they
 * never reach Inertia payloads or audit entries. Audit entries are written
 * explicitly for create/update/delete instead of via RecordsAuditLog, so
 * rotation can be recorded without ever touching the secret itself.
 *
 * @property string $id
 * @property string $organization_id
 * @property IntegrationProvider $provider
 * @property string $name
 * @property string|null $base_url
 * @property array<string, string> $credentials
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User|null $createdBy
 * @property-read Collection<int, IntegrationConnection> $connections
 */
#[Fillable(['provider', 'name', 'base_url', 'credentials', 'created_by'])]
class IntegrationAccount extends Model
{
    /** @use HasFactory<IntegrationAccountFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'credentials',
    ];

    /**
     * The API client for this account's provider, scoped to the Jira project
     * or Linear team an engagement syncs.
     */
    public function client(string $externalProjectKey): ProviderClient
    {
        return match ($this->provider) {
            IntegrationProvider::Jira => new JiraClient(
                (string) $this->base_url,
                $this->credentials['email'] ?? '',
                $this->credentials['api_token'] ?? '',
                $externalProjectKey,
            ),
            IntegrationProvider::Linear => new LinearClient(
                $this->credentials['api_token'] ?? '',
                $externalProjectKey,
            ),
        };
    }

    /**
     * Rename the account, move its site URL, or rotate its credentials. The
     * audit entry records that a rotation happened, never the secret.
     *
     * @param  array<string, string>|null  $credentials
     */
    public function updateDetails(string $name, ?string $baseUrl, ?array $credentials, ?User $actor = null): void
    {
        $this->name = $name;

        if ($this->provider === IntegrationProvider::Jira) {
            $this->base_url = $baseUrl;
        }

        if ($credentials !== null) {
            $this->credentials = $credentials;
        }

        $this->save();

        AuditLog::record('integration_account.updated', $this, [
            'provider' => $this->provider->value,
            'name' => $this->name,
            'base_url' => $this->base_url,
            'credentials_rotated' => $credentials !== null,
        ], $actor);
    }

    /**
     * Delete an account no engagement uses. Connected engagements hold the
     * account in place — an owner disconnects them first.
     */
    public function deleteAccount(?User $actor = null): void
    {
        if ($this->connections()->exists()) {
            throw ValidationException::withMessages([
                'account' => __(':name still syncs :count engagement(s) — disconnect them first.', [
                    'name' => $this->name,
                    'count' => (string) $this->connections()->count(),
                ]),
            ]);
        }

        AuditLog::record('integration_account.deleted', $this, [
            'provider' => $this->provider->value,
            'name' => $this->name,
        ], $actor);

        $this->delete();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<IntegrationConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'credentials' => 'encrypted:array',
        ];
    }
}
