<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phase_scheduling_policies', function (Blueprint $table) {
            $table->foreignUuid('phase_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedInteger('season_revision');
            $table->unsignedInteger('coverage_revision');
            foreach (['weekly_target_minutes', 'planned_cap_minutes', 'planned_warning_minutes', 'actual_warning_minutes'] as $field) {
                $table->unsignedInteger($field);
            }
        });
        Schema::create('shift_desirabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('phase_id');
            $table->uuid('shift_id');
            $table->string('scope', 10);
            // baseline: *, weekday: ISO 1–7, date: YYYY-MM-DD.
            $table->string('scope_key', 10);
            $table->string('classification', 12);
            $table->foreign(['shift_id', 'phase_id'])->references(['id', 'phase_id'])->on('shift_definitions')->cascadeOnDelete();
            $table->foreign('phase_id')->references('phase_id')->on('phase_scheduling_policies')->restrictOnDelete();
            $table->unique(['shift_id', 'scope', 'scope_key']);
        });
        Schema::create('phase_policy_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('phase_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('reason', 500);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestampTz('occurred_at');
            $table->unique(['phase_id', 'revision']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE phase_scheduling_policies ADD CHECK (revision > 0 AND season_revision > 0 AND coverage_revision > 0 AND weekly_target_minutes >= 0 AND planned_cap_minutes >= weekly_target_minutes AND planned_warning_minutes >= 0 AND actual_warning_minutes >= 0)');
            DB::statement("ALTER TABLE shift_desirabilities ADD CHECK (classification IN ('desirable', 'neutral', 'undesirable') AND ((scope = 'baseline' AND scope_key = '*') OR (scope = 'weekday' AND scope_key ~ '^[1-7]$') OR (scope = 'date' AND scope_key ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$')))");
        }
    }

    public function down(): void
    {
        foreach (['phase_policy_changes', 'shift_desirabilities', 'phase_scheduling_policies'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
