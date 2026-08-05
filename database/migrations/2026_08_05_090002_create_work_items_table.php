<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Execution work on an engagement (FA-7, FA-8): issues imported from Jira or
 * Linear (external_* columns identify them at the provider, uniquely per
 * connection) and manual items recorded in standalone mode (no connection,
 * created_by set). Estimates keep their native unit — seconds, points or
 * days — conversion to cost is an analysis-time concern. The connection FK
 * nulls on delete so imported history outlives its integration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('external_id')->nullable();
            $table->string('external_key')->nullable();
            $table->string('external_url')->nullable();
            $table->string('title');
            $table->string('external_status')->nullable();
            $table->string('state');
            $table->string('type')->nullable();
            $table->string('assignee_name')->nullable();
            $table->decimal('estimate_value', 10, 2)->nullable();
            $table->string('estimate_unit')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'external_id']);
            $table->index(['engagement_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_items');
    }
};
