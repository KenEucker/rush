<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->uuid('membership_id');
            $table->uuid('operation_id');
            $table->string('fingerprint', 64);
            $table->json('result');
            $table->timestampTz('created_at');
            $table->primary(['membership_id', 'operation_id']);
            $table->foreign('membership_id')->references('id')->on('organization_memberships')->restrictOnDelete();
        });
        Schema::create('sync_streams', function (Blueprint $table) {
            $table->uuid('membership_id')->primary();
            $table->uuid('generation');
            $table->string('role');
            $table->unsignedInteger('sequence')->default(0);
            $table->foreign('membership_id')->references('id')->on('organization_memberships')->cascadeOnDelete();
        });
        Schema::create('sync_profiles', function (Blueprint $table) {
            $table->uuid('membership_id');
            $table->uuid('record_id');
            $table->json('value');
            $table->primary(['membership_id', 'record_id']);
            $table->foreign('membership_id')->references('membership_id')->on('sync_streams')->cascadeOnDelete();
        });
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->uuid('membership_id');
            $table->unsignedInteger('sequence');
            $table->uuid('record_id');
            $table->json('value')->nullable();
            $table->primary(['membership_id', 'sequence']);
            $table->foreign('membership_id')->references('membership_id')->on('sync_streams')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
        Schema::dropIfExists('sync_profiles');
        Schema::dropIfExists('sync_streams');
        Schema::dropIfExists('sync_operations');
    }
};
