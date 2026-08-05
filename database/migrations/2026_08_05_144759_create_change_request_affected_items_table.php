<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The baseline items a change request touches (FA-12) — linked records on
 * the current approved baseline, never free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_request_affected_items', function (Blueprint $table): void {
            $table->foreignUuid('change_request_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('baseline_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['change_request_id', 'baseline_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_request_affected_items');
    }
};
