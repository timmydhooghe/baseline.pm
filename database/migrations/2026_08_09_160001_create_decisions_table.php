<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The decision ledger (FA-18): why the engagement is the way it is. Each
 * record carries the context it was taken in, the alternatives that were
 * weighed, who was in the room, what it cost in scope, budget and time, and
 * the evidence behind it. Drafts — raised by hand or extracted from a
 * meeting transcript — carry no weight until confirmed. Confirmed records
 * are never rewritten: a later decision supersedes them and the chain stays
 * readable. Shared records freeze a customer-facing snapshot the customer
 * acknowledges in the portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('source');
            $table->string('title');
            $table->text('context');
            $table->text('decision')->nullable();
            $table->jsonb('alternatives')->nullable();
            $table->jsonb('participants')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->text('impact_scope')->nullable();
            $table->bigInteger('impact_budget_cents')->nullable();
            $table->integer('impact_timeline_days')->nullable();
            $table->string('visibility');
            $table->uuid('supersedes_id')->nullable()->unique();
            $table->date('decided_on')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('transcript_excerpt')->nullable();
            $table->foreignUuid('customer_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignUuid('acknowledged_by')->nullable()->constrained('stakeholders')->nullOnDelete();
            $table->string('acknowledged_by_name')->nullable();
            $table->text('acknowledgement_comment')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['engagement_id', 'status']);
        });

        /*
         * The supersedes-chain points at this same table, so its constraint
         * is added afterwards: inside the create the foreign key would be
         * issued before the primary key it references exists. Unique, because
         * two records replacing the same decision would fork the chain.
         */
        Schema::table('decisions', function (Blueprint $table): void {
            $table->foreign('supersedes_id')->references('id')->on('decisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};
