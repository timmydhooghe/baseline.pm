<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only trail of baseline item rows a deliverable record has
 * pointed at (FA-22's version history): one row per baseline version the
 * deliverable appears in, written at provisioning and at every minted
 * version. Baseline items carry no lineage of their own — this table is it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverable_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('deliverable_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('baseline_item_id')->constrained('baseline_items')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->unique(['deliverable_id', 'baseline_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverable_versions');
    }
};
