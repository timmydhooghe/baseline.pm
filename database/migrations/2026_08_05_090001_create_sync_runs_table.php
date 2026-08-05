<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One record per sync pass against a provider — the always-visible sync
 * status FA-7 requires. Counts hold what the pass upserted (work items,
 * worklogs, releases); failed runs keep the error so a broken connection is
 * diagnosable from the work page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_connection_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->jsonb('counts')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['integration_connection_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
