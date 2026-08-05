<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commitment ledger of an engagement (FA-5, FA-6). A baseline is drafted
 * in the builder wizard, submitted as an immutable review snapshot, and — once
 * approved — never edited in place: every approved change request creates the
 * next version. The rate card version is pinned at creation so every cost
 * figure stays derivable forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baselines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft');
            $table->string('commercial_model');
            $table->bigInteger('contract_value_cents');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('execution_mode');
            $table->foreignUuid('rate_card_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->jsonb('acknowledged_checks')->default('{}');
            $table->foreignUuid('review_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->foreignUuid('customer_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['engagement_id', 'version']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baselines');
    }
};
