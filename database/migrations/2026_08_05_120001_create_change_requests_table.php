<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change requests (FA-11..13). Introduced here for scope-creep-born drafts:
 * triaging an unmapped work item as a potential change pre-fills a draft CR
 * from the item (FA-9) — effort seeded from the provider estimate and logged
 * time, and work_started_at snapshotting the earliest evidence of execution
 * so the contractual breach risk (work started before CR approval) survives
 * later sync updates. The full lifecycle — assessment, customer proposal,
 * portal approval, next baseline version — arrives with change control.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('title');
            $table->text('what');
            $table->text('why')->nullable();
            $table->string('origin')->nullable();
            $table->decimal('estimated_days', 7, 2)->nullable();
            $table->unsignedBigInteger('logged_seconds')->default(0);
            $table->timestamp('work_started_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['engagement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};
