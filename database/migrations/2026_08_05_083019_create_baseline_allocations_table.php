<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-mix lines behind a baseline's cost budget (FA-5 step 4): estimated
 * days per rate card role, priced against the baseline's pinned rate card
 * version — cost is always derived, never typed. Lines without an item are
 * delivery-management effort, allocated pro-rata across deliverables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baseline_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('baseline_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('baseline_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('rate_card_role_id')->constrained()->restrictOnDelete();
            $table->decimal('days', 7, 2);
            $table->timestamps();

            $table->index('baseline_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baseline_allocations');
    }
};
