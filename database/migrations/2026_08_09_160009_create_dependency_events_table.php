<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The evidence trail behind a dependency (FA-20): every request, reminder
 * and escalation, plus the moment it arrived or was waived. Append-only —
 * an attribution that can be edited afterwards is worth nothing when a
 * milestone slip has to be defended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependency_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('dependency_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('channel')->nullable();
            $table->text('note')->nullable();
            $table->string('evidence_url', 2048)->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['dependency_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dependency_events');
    }
};
