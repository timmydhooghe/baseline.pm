<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Credentials move off the per-engagement connection onto org-level
 * integration accounts. Every distinct credential set in use becomes an
 * account (identical credentials shared by several engagements collapse
 * into one), each connection points at its account, and the credential
 * columns disappear from connections. Encryption is non-deterministic, so
 * deduplication hashes the decrypted payload — the ciphertext itself is
 * copied verbatim, both models use the same encrypted:array format.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->foreignUuid('integration_account_id')
                ->nullable()
                ->constrained('integration_accounts')
                ->restrictOnDelete();
        });

        $this->backfillAccounts();

        /*
         * A connected row without credentials cannot sync and would break the
         * "connected means an account is attached" invariant — flip it.
         */
        DB::table('integration_connections')
            ->where('status', 'connected')
            ->whereNull('integration_account_id')
            ->update(['status' => 'disconnected', 'disconnected_at' => now()]);

        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropColumn(['credentials', 'base_url', 'external_project_name']);
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->text('credentials')->nullable();
            $table->string('base_url')->nullable();
            $table->string('external_project_name')->nullable();
        });

        $accounts = DB::table('integration_accounts')->get()->keyBy('id');

        foreach (DB::table('integration_connections')->whereNotNull('integration_account_id')->get() as $connection) {
            $account = $accounts->get($connection->integration_account_id);

            if ($account === null) {
                continue;
            }

            DB::table('integration_connections')->where('id', $connection->id)->update([
                'credentials' => $account->credentials,
                'base_url' => $account->base_url,
            ]);
        }

        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('integration_account_id');
        });
    }

    private function backfillAccounts(): void
    {
        /** @var array<string, string> $accountIds dedupe key => account id */
        $accountIds = [];

        /** @var array<string, array<string, true>> $takenNames organization id => set of claimed names */
        $takenNames = [];

        $connections = DB::table('integration_connections')
            ->whereNotNull('credentials')
            ->orderBy('created_at')
            ->get();

        foreach ($connections as $connection) {
            $payload = Crypt::decryptString($connection->credentials);
            $dedupeKey = implode('|', [
                $connection->organization_id,
                $connection->provider,
                (string) $connection->base_url,
                hash('sha256', $payload),
            ]);

            if (! isset($accountIds[$dedupeKey])) {
                $name = $this->claimName($takenNames, $connection->organization_id, $connection->provider, $connection->base_url);
                $id = (string) Str::uuid7();

                DB::table('integration_accounts')->insert([
                    'id' => $id,
                    'organization_id' => $connection->organization_id,
                    'provider' => $connection->provider,
                    'name' => $name,
                    'base_url' => $connection->base_url,
                    'credentials' => $connection->credentials,
                    'created_by' => $connection->connected_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $accountIds[$dedupeKey] = $id;
            }

            DB::table('integration_connections')
                ->where('id', $connection->id)
                ->update(['integration_account_id' => $accountIds[$dedupeKey]]);
        }
    }

    /**
     * A readable account name, suffixed when two distinct credential sets on
     * the same org would otherwise collide on the unique(organization, name)
     * index — e.g. two Jira tokens for the same site.
     *
     * @param  array<string, array<string, true>>  $takenNames
     */
    private function claimName(array &$takenNames, string $organizationId, string $provider, ?string $baseUrl): string
    {
        $host = $baseUrl !== null ? parse_url($baseUrl, PHP_URL_HOST) : null;
        $base = match ($provider) {
            'jira' => 'Jira — '.(is_string($host) ? $host : 'site'),
            default => 'Linear API key',
        };

        $name = $base;
        $suffix = 2;

        while (isset($takenNames[$organizationId][$name])) {
            $name = "{$base} ({$suffix})";
            $suffix++;
        }

        $takenNames[$organizationId][$name] = true;

        return $name;
    }
};
