<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement-level final acceptance requests (FA-24): the gate before
 * Completed. Each submission is its own record with frozen twin snapshots;
 * the customer's decision is stored inline and the record becomes immutable
 * once decided or withdrawn — a resubmission is a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_acceptances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->foreignUuid('review_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->foreignUuid('customer_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision')->nullable();
            $table->foreignUuid('stakeholder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stakeholder_name')->nullable();
            $table->text('comment')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('engagement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_acceptances');
    }
};
