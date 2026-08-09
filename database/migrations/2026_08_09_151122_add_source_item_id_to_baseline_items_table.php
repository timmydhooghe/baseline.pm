<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lineage between baseline versions (FA-6): when approval mints the next
 * version, every copied item records the item it was copied from. References
 * held against an older version — a change request's schedule impact
 * assessed before another change advanced the baseline — rebase onto the
 * current version by walking this chain instead of silently missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baseline_items', function (Blueprint $table): void {
            $table->foreignUuid('source_item_id')->nullable()->after('payment_trigger')->constrained('baseline_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('baseline_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_item_id');
        });
    }
};
