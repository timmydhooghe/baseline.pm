<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The living execution and acceptance record of a contracted deliverable
 * (FA-22, FA-23). The contractual definition (title, value, criteria) stays
 * on the immutable baseline item; this row carries what moves — progress,
 * confidence, forecast, milestone assignment, per-criterion evidence — and
 * the acceptance state. When an approved change request mints the next
 * baseline version the record is repointed at the new item row, so it
 * survives versioning; the value signed off is denormalized at acceptance so
 * the position rail's accepted line never drifts from the signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('baseline_item_id')->unique()->constrained('baseline_items')->cascadeOnDelete();
            $table->foreignUuid('milestone_item_id')->nullable()->constrained('baseline_items')->nullOnDelete();
            $table->string('status');
            $table->unsignedTinyInteger('progress');
            $table->string('confidence');
            $table->date('forecast_date')->nullable();
            $table->jsonb('criteria_state')->nullable();
            $table->foreignUuid('review_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->foreignUuid('customer_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->bigInteger('accepted_value_cents')->nullable();
            $table->timestamps();

            $table->index(['engagement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverables');
    }
};
