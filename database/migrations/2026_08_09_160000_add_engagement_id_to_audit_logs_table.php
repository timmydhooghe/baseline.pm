<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FA-21 asks the audit log to be linked from everywhere. The subject morph
 * says what an entry is about, but not which engagement it belongs to —
 * answering that would mean joining every governed table in turn. The
 * engagement is resolved once at append time and stored beside the subject,
 * so an engagement's trail is one indexed read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignUuid('engagement_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();

            $table->index(['engagement_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['engagement_id', 'created_at']);
            $table->dropConstrainedForeignId('engagement_id');
        });
    }
};
