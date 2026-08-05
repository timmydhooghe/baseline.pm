<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer decisions on submitted deliverables (FA-23): accept, reject or
 * request clarification, each tied to the frozen customer snapshot it was
 * made against. Responses are append-only — the stakeholder's name is
 * denormalized so the record survives the stakeholder, and there is no
 * updated_at because a response never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverable_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('deliverable_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('snapshot_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('stakeholder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stakeholder_name');
            $table->string('decision');
            $table->text('comment')->nullable();
            $table->timestamp('created_at');

            $table->index('deliverable_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverable_responses');
    }
};
