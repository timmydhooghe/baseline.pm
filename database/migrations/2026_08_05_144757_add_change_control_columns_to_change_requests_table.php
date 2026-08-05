<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The change-control lifecycle on top of drift-born drafts (FA-11..13):
 * the rate card version the assessment is priced with (pinned when the
 * assessment starts, so cost stays derived), the numeric customer price,
 * structured schedule impact (a milestone reference plus a day count — no
 * free-text dates), the proposal's frozen twin snapshots, the respond-by
 * deadline with its reminder bookkeeping, and the baseline version minted
 * by approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $table): void {
            $table->foreignUuid('rate_card_version_id')->nullable()->after('origin')->constrained()->restrictOnDelete();
            $table->bigInteger('customer_price_cents')->nullable()->after('logged_seconds');
            $table->foreignUuid('impact_milestone_id')->nullable()->after('customer_price_cents')->constrained('baseline_items')->nullOnDelete();
            $table->integer('impact_days')->nullable()->after('impact_milestone_id');
            $table->text('scope_added')->nullable()->after('impact_days');
            $table->text('scope_removed')->nullable()->after('scope_added');
            $table->text('alternatives')->nullable()->after('scope_removed');
            $table->foreignUuid('review_snapshot_id')->nullable()->after('alternatives')->constrained('snapshots')->nullOnDelete();
            $table->foreignUuid('customer_snapshot_id')->nullable()->after('review_snapshot_id')->constrained('snapshots')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('customer_snapshot_id');
            $table->timestamp('respond_by')->nullable()->after('submitted_at');
            $table->timestamp('last_reminded_at')->nullable()->after('respond_by');
            $table->timestamp('decided_at')->nullable()->after('last_reminded_at');
            $table->foreignUuid('minted_baseline_id')->nullable()->after('decided_at')->constrained('baselines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rate_card_version_id');
            $table->dropConstrainedForeignId('impact_milestone_id');
            $table->dropConstrainedForeignId('review_snapshot_id');
            $table->dropConstrainedForeignId('customer_snapshot_id');
            $table->dropConstrainedForeignId('minted_baseline_id');
            $table->dropColumn([
                'customer_price_cents',
                'impact_days',
                'scope_added',
                'scope_removed',
                'alternatives',
                'submitted_at',
                'respond_by',
                'last_reminded_at',
                'decided_at',
            ]);
        });
    }
};
