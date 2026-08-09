<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The risk register (FA-19): probability × impact, an owner who carries it,
 * the records it threatens, a mitigation plan, and structured exposure
 * priced from a pinned rate card version — days × role → €, never a typed
 * amount. The pinned version is what keeps the weighted exposure that feeds
 * the margin risk band (FA-17) traceable to its source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('probability');
            $table->string('impact');
            $table->string('status');
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('mitigation')->nullable();
            $table->string('visibility');
            $table->foreignUuid('rate_card_version_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['engagement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risks');
    }
};
