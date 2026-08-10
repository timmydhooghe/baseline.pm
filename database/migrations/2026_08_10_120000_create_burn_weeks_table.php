<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recorded week of burn (FA-16). Recording freezes the week as an immutable
 * snapshot: the rows never change afterwards, and a correction records the
 * week again — the earlier entry stays on the ledger, marked superseded by the
 * one that replaced it. Cost-to-date, forecast-at-completion and the margin
 * forecast read the current entry per week only.
 *
 * `rate_card_version_id` pins the rates the week was priced at and
 * `cost_cents` freezes what those rates produced, so a later rate card version
 * can never restate a week that is already on record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('burn_weeks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->foreignUuid('rate_card_version_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('cost_cents');
            $table->text('note')->nullable();
            $table->timestamp('recorded_at');
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->uuid('superseded_by_id')->nullable();
            $table->timestamps();

            $table->index(['engagement_id', 'week_start']);
            $table->index(['engagement_id', 'superseded_at']);
        });

        /*
         * The supersede pointer references this same table, so its constraint
         * waits for a second statement: PostgreSQL adds the primary key after
         * the foreign keys within a single create, and a self-reference has
         * nothing unique to point at until it exists.
         */
        Schema::table('burn_weeks', function (Blueprint $table): void {
            $table->foreign('superseded_by_id')->references('id')->on('burn_weeks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('burn_weeks');
    }
};
