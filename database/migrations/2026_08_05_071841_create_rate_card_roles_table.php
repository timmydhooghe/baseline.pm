<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_card_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rate_card_version_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->bigInteger('cost_per_day_cents');
            $table->bigInteger('sell_per_day_cents');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['rate_card_version_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_card_roles');
    }
};
