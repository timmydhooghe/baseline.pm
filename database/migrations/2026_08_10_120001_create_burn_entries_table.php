<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of a recorded burn week (FA-16): days spent by a person or a
 * profile, against a rate card role. Cost is derived — days × the role's cost
 * rate on the week's pinned version — and frozen into `cost_cents` at the
 * moment of recording, because the week is a snapshot and a snapshot that
 * recomputes is not one.
 *
 * `source` records where the number came from: a time-tracking integration,
 * the progress-derived suggestion, or a manager typing it. The suggestion is
 * only ever a starting point, so a line that was suggested and then edited is
 * recorded as manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('burn_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('burn_week_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rate_card_role_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role_name');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('person_name')->nullable();
            $table->decimal('days', 8, 2);
            $table->string('source');
            $table->unsignedBigInteger('cost_per_day_cents');
            $table->unsignedBigInteger('cost_cents');
            $table->timestamps();

            $table->index('burn_week_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('burn_entries');
    }
};
