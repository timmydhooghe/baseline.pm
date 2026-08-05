<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The work mapping (FA-8): a work item linked to the deliverable it belongs
 * to, recording who linked it and when. One mapping per work item — relinking
 * replaces it (the audit log keeps the history). Work without a row here is
 * unmapped and surfaces as potential scope creep (FA-9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('baseline_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('work_item_id');
            $table->index('baseline_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_links');
    }
};
