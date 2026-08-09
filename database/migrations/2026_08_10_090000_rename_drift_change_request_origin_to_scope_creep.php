<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The product calls unmapped work "scope creep" (FA-9), and the change
 * request origin was the last place still calling it drift — a value that
 * reached the customer-facing label, the API contract and the form markup.
 * Renaming the enum case alone would leave every stored row unreadable to
 * it, so the recorded history moves with the vocabulary.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('change_requests')
            ->where('origin', 'drift')
            ->update(['origin' => 'scope_creep']);
    }

    public function down(): void
    {
        DB::table('change_requests')
            ->where('origin', 'scope_creep')
            ->update(['origin' => 'drift']);
    }
};
