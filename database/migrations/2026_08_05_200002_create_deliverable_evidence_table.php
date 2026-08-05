<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The evidence list on a deliverable record (FA-22): releases, demos, test
 * reports and documents that back progress and acceptance. Each item carries
 * its own visibility — internal evidence never reaches a customer snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverable_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('deliverable_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('label');
            $table->string('url', 2048)->nullable();
            $table->string('visibility');
            $table->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('deliverable_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverable_evidence');
    }
};
