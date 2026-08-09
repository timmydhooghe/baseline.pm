<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The records a decision touches (FA-18): baseline items, deliverables,
 * change requests, risks, dependencies or work items — linked records, never
 * free text, so "why was SSO excluded?" answers itself from both ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('decision_id')->constrained()->cascadeOnDelete();
            $table->uuidMorphs('linked');
            $table->timestamps();

            $table->unique(['decision_id', 'linked_type', 'linked_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_links');
    }
};
