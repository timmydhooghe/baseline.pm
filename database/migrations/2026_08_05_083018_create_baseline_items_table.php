<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typed contract items on a baseline (FA-5 step 3): deliverables, milestones,
 * assumptions, exclusions and customer responsibilities. One table for all
 * types — the type-specific columns (owner, value, acceptance criteria for
 * deliverables; date and payment trigger for milestones) stay null for the
 * others. Every item traces to a contract clause.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baseline_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('baseline_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('position');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('clause_reference');
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('value_cents')->nullable();
            $table->jsonb('acceptance_criteria')->nullable();
            $table->date('baseline_date')->nullable();
            $table->string('payment_trigger')->nullable();
            $table->timestamps();

            $table->index(['baseline_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baseline_items');
    }
};
