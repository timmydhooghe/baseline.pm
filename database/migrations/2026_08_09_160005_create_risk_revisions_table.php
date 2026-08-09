<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rating history of a risk (FA-19), appended on every re-rating. A
 * register that only shows today's rating cannot tell a risk that was always
 * high from one that is getting worse — and worsening is exactly what has to
 * surface on Today (FA-25). Each row freezes the rating, the score and the
 * exposure it was worth at that moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('risk_id')->constrained()->cascadeOnDelete();
            $table->string('probability');
            $table->string('impact');
            $table->unsignedTinyInteger('score');
            $table->string('status');
            $table->bigInteger('exposure_cents')->nullable();
            $table->bigInteger('weighted_exposure_cents')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['risk_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_revisions');
    }
};
