<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One drift-born change request per work item, enforced at the database:
 * the row lock in WorkItem::triage() serializes concurrent classifications,
 * and this index is the backstop should any future write path skip it.
 * Nullable, so change requests without an origin item are unaffected. If
 * change control later needs several requests per item across a lifetime
 * (e.g. a rejected one followed by a new draft), it must revisit this
 * constraint deliberately rather than inherit duplicates by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $table): void {
            $table->unique('work_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table): void {
            $table->dropUnique(['work_item_id']);
        });
    }
};
