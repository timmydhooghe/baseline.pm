<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract files attached to a baseline (FA-5 step 2): SOW, proposal,
 * annexes. Files live on the private local disk and stay internal until
 * baseline approval shares them through the portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baseline_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('baseline_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('baseline_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baseline_documents');
    }
};
