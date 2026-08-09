<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a risk threatens (FA-19): deliverables, milestones, change requests
 * or dependencies as linked records. The new-risk form uses record chips
 * precisely so the threat can be read from the threatened record too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('risk_id')->constrained()->cascadeOnDelete();
            $table->uuidMorphs('threatened');
            $table->timestamps();

            $table->unique(['risk_id', 'threatened_type', 'threatened_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_links');
    }
};
