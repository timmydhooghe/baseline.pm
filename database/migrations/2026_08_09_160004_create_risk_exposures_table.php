<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A structured exposure line on a risk (FA-19): effort at risk as days for
 * one rate card role, priced at the version pinned on the risk. The euro
 * figure is derived — the register never takes a typed amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_exposures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('risk_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rate_card_role_id')->constrained()->cascadeOnDelete();
            $table->decimal('days', 8, 2);
            $table->timestamps();

            $table->unique(['risk_id', 'rate_card_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_exposures');
    }
};
