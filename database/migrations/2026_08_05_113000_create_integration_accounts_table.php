<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization-level provider account (FA-7): the named credential set a
 * Jira site or Linear workspace is reached with. Accounts are managed by the
 * organization owner and reused across engagements — an engagement connects
 * by picking an account, never by re-entering credentials. Credentials are
 * stored encrypted and never leave the backend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->text('credentials');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_accounts');
    }
};
