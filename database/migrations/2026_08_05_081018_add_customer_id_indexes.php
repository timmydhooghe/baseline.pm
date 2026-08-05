<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL does not index referencing foreign-key columns automatically,
     * and both tables are queried per customer (counts and customer pages).
     */
    public function up(): void
    {
        Schema::table('stakeholders', function (Blueprint $table) {
            $table->index('customer_id');
        });

        Schema::table('engagements', function (Blueprint $table) {
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('stakeholders', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
        });

        Schema::table('engagements', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
        });
    }
};
