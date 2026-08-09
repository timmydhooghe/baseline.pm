<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dependency register (FA-20): what the engagement is waiting for, from
 * whom, and by when. The responsible party is a person — a named customer
 * stakeholder or a named colleague — because "the client" cannot be chased.
 * Outstanding items accrue day-for-day delay against their required date,
 * attributed to the owing party, and customer-owed items surface in the
 * portal action list.
 *
 * `settled_on` is the day the item stopped being outstanding — the day it
 * arrived, or the day it was waived. It is what stops the delay clock; the
 * status says which of the two happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('party');
            $table->foreignUuid('responsible_stakeholder_id')->nullable()->constrained('stakeholders')->nullOnDelete();
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('required_on');
            $table->string('status');
            $table->date('settled_on')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->string('visibility');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['engagement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dependencies');
    }
};
