<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('timezone', 100);
            $table->unsignedSmallInteger('week_starts_on');
            $table->unsignedInteger('revision');
            $table->timestampsTz();
            $table->index(['organization_id', 'starts_on']);
        });
        Schema::create('phases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('season_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestampsTz();
            $table->index(['season_id', 'starts_on']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE seasons ADD CONSTRAINT season_calendar_valid CHECK (ends_on >= starts_on AND week_starts_on BETWEEN 1 AND 7 AND revision > 0)');
            DB::statement('ALTER TABLE phases ADD CONSTRAINT phase_dates_valid CHECK (ends_on >= starts_on)');
        }
        Schema::create('season_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('season_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('reason', 500);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestampTz('occurred_at');
            $table->unique(['season_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_changes');
        Schema::dropIfExists('phases');
        Schema::dropIfExists('seasons');
    }
};
