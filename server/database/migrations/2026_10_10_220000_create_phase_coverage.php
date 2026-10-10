<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phase_coverages', function (Blueprint $table) {
            $table->foreignUuid('phase_id')->primary()->constrained()->restrictOnDelete();
            $table->string('model', 16);
            $table->unsignedInteger('revision');
            $table->unique(['phase_id', 'model']);
        });
        foreach (['shift_definitions', 'coverage_areas', 'coverage_groups'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->uuid('id')->primary();
                $table->foreignUuid('phase_id')->constrained()->restrictOnDelete();
                $table->string('name', 120);
                $table->unique(['id', 'phase_id']);
                if ($name === 'shift_definitions') {
                    $table->string('start_time', 5);
                    $table->unsignedInteger('duration_minutes');
                }
            });
        }
        Schema::create('coverage_group_areas', function (Blueprint $table) {
            $table->uuid('phase_id');
            $table->uuid('group_id');
            $table->uuid('area_id');
            $table->primary(['group_id', 'area_id']);
            $table->foreign(['group_id', 'phase_id'])->references(['id', 'phase_id'])->on('coverage_groups')->restrictOnDelete();
            $table->foreign(['area_id', 'phase_id'])->references(['id', 'phase_id'])->on('coverage_areas')->restrictOnDelete();
        });
        Schema::create('coverage_staffing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('phase_id');
            $table->string('model', 16);
            $table->uuid('shift_id');
            $table->uuid('area_id')->nullable();
            $table->uuid('group_id')->nullable();
            $table->unsignedInteger('positions');
            $table->foreign(['phase_id', 'model'])->references(['phase_id', 'model'])->on('phase_coverages')->restrictOnDelete();
            $table->foreign(['shift_id', 'phase_id'])->references(['id', 'phase_id'])->on('shift_definitions')->restrictOnDelete();
            $table->foreign(['area_id', 'phase_id'])->references(['id', 'phase_id'])->on('coverage_areas')->restrictOnDelete();
            $table->foreign(['group_id', 'phase_id'])->references(['id', 'phase_id'])->on('coverage_groups')->restrictOnDelete();
            $table->unique(['shift_id', 'area_id']);
            $table->unique(['shift_id', 'group_id']);
        });
        Schema::create('phase_coverage_changes', function (Blueprint $table) {
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
        Schema::table('official_assignments', fn (Blueprint $table) => $table->json('shift_context')->nullable());
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE phase_coverages ADD CHECK (model IN ('dedicated', 'shared') AND revision > 0)");
            DB::statement("ALTER TABLE shift_definitions ADD CHECK (duration_minutes > 0 AND start_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$')");
            DB::statement("ALTER TABLE coverage_staffing ADD CHECK (positions >= 0 AND ((model = 'dedicated' AND area_id IS NOT NULL AND group_id IS NULL) OR (model = 'shared' AND group_id IS NOT NULL AND area_id IS NULL)))");
        }
    }

    public function down(): void
    {
        Schema::table('official_assignments', fn (Blueprint $table) => $table->dropColumn('shift_context'));
        foreach (['phase_coverage_changes', 'coverage_staffing', 'coverage_group_areas', 'coverage_groups', 'coverage_areas', 'shift_definitions', 'phase_coverages'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
