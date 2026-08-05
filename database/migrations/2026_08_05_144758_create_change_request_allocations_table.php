<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The structured effort assessment of a change request (FA-12): estimated
 * days per rate card role, priced at the version pinned on the change
 * request. Internal cost is always derived from these lines — never typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_request_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('change_request_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rate_card_role_id')->constrained()->restrictOnDelete();
            $table->decimal('days', 7, 2);
            $table->timestamps();

            $table->index('change_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_request_allocations');
    }
};
