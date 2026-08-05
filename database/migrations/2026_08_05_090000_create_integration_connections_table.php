<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-engagement connection to an execution tool (FA-7): Jira or Linear,
 * one row per provider so mixed mode can run both. The row survives a
 * disconnect — credentials are wiped but the connection and everything it
 * imported stay, so a later reconnect resyncs into the same history.
 * Credentials are stored encrypted and never leave the backend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('status');
            $table->text('credentials')->nullable();
            $table->string('base_url')->nullable();
            $table->string('external_project_key');
            $table->string('external_project_name')->nullable();
            $table->foreignUuid('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->foreignUuid('disconnected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['engagement_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');
    }
};
