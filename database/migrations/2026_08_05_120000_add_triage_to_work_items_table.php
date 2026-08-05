<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Triage of unmapped work (FA-9): each drift item is classified — existing
 * scope, potential change, operational or dismissed — recorded with who
 * classified it and when. A null triage_status on an unmapped item means it
 * is still in the triage inbox and its potential price counts toward the
 * engagement's unbilled risk (FA-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->string('triage_status')->nullable()->after('estimate_unit');
            $table->text('triage_note')->nullable()->after('triage_status');
            $table->foreignUuid('triaged_by')->nullable()->after('triage_note')->constrained('users')->nullOnDelete();
            $table->timestamp('triaged_at')->nullable()->after('triaged_by');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('triaged_by');
            $table->dropColumn(['triage_status', 'triage_note', 'triaged_at']);
        });
    }
};
