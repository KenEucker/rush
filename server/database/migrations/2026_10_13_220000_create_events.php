<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->unique(['id', 'organization_id']);
        });
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('season_id');
            $table->foreign(['season_id', 'organization_id'])->references(['id', 'organization_id'])->on('seasons')->restrictOnDelete();
            $table->string('name', 120);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('timezone');
            $table->unsignedInteger('season_revision');
            $table->boolean('paid');
            $table->string('target_type', 16);
            $table->unsignedInteger('target_value')->nullable();
            $table->boolean('counts_scheduled');
            $table->unsignedInteger('revision');
            $table->timestampsTz();
            $table->unique(['id', 'organization_id']);
            $table->index(['season_id', 'starts_at']);
        });
        Schema::create('event_attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('organization_id');
            $table->uuid('organization_membership_id');
            $table->boolean('is_assigned');
            $table->timestampsTz();
            $table->foreign(['event_id', 'organization_id'])->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
            $table->foreign(['organization_membership_id', 'organization_id'])->references(['id', 'organization_id'])->on('organization_memberships')->restrictOnDelete();
            $table->unique(['event_id', 'organization_membership_id']);
        });
        Schema::create('event_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('reason', 500);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestampTz('occurred_at');
            $table->unique(['event_id', 'revision']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE events ADD CHECK (ends_at > starts_at AND revision > 0 AND season_revision > 0)');
            DB::statement("ALTER TABLE events ADD CHECK ((target_type = 'none' AND target_value IS NULL) OR (target_type = 'number' AND target_value IS NOT NULL AND target_value BETWEEN 0 AND 10000) OR (target_type = 'percentage' AND target_value IS NOT NULL AND target_value BETWEEN 0 AND 100))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_changes');
        Schema::dropIfExists('event_attendances');
        Schema::dropIfExists('events');
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropUnique(['id', 'organization_id']);
        });
    }
};
