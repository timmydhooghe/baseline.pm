<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published weekly reports (FA-26). A row exists only once a week's report is
 * published — drafts are derived from evidence on request and never stored,
 * so they cannot go stale. Publishing freezes twin snapshots (internal and
 * customer, the customer one stripped of cost and margin) and the row itself
 * becomes immutable; one report per engagement per week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->foreignUuid('review_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->foreignUuid('customer_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->timestamp('published_at');
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['engagement_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
