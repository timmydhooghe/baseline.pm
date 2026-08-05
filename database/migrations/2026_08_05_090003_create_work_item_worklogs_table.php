<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time logged against a work item (FA-7): Jira worklogs synced by external
 * id (uniquely per item), manual entries recorded by hand in standalone
 * mode. Seconds is the storage unit; the weekly burn flow (FA-16) reads
 * these as its time-tracking source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_worklogs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('author_name');
            $table->unsignedBigInteger('seconds');
            $table->date('logged_on');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['work_item_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_worklogs');
    }
};
