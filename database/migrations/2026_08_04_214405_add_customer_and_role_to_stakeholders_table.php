<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stakeholders become contacts of a customer record with a portal role.
     * The table is empty before WEBAPP-16, so the column can be non-nullable.
     */
    public function up(): void
    {
        Schema::table('stakeholders', function (Blueprint $table) {
            $table->foreignUuid('customer_id')->after('organization_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('viewer')->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('stakeholders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('role');
        });
    }
};
