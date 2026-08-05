<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Releases synced from a provider (FA-7): Jira project versions, Linear
 * releases. Evidence material for deliverable acceptance later (FA-22).
 * Like work items, they are unique per connection at the provider and
 * outlive a deleted connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('integration_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->boolean('released')->default(false);
            $table->date('released_on')->nullable();
            $table->string('external_url')->nullable();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'external_id']);
            $table->index('engagement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
