<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deliverables and milestones a dependency blocks (FA-20). The link is
 * what turns a late item into a dated consequence: the delay lands day for
 * day on the affected records and the attribution travels with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependency_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('dependency_id')->constrained()->cascadeOnDelete();
            $table->uuidMorphs('affected');
            $table->timestamps();

            $table->unique(['dependency_id', 'affected_type', 'affected_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dependency_links');
    }
};
