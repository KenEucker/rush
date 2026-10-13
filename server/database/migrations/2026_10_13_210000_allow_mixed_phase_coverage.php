<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coverage_staffing', function (Blueprint $table) {
            $table->dropForeign(['phase_id', 'model']);
            $table->foreign('phase_id')->references('phase_id')->on('phase_coverages')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE phase_coverages DROP CONSTRAINT phase_coverages_check');
            DB::statement('ALTER TABLE phase_coverages ADD CONSTRAINT phase_coverages_revision_check CHECK (revision > 0)');
        }
        Schema::table('phase_coverages', function (Blueprint $table) {
            $table->dropUnique(['phase_id', 'model']);
            $table->dropColumn('model');
        });
    }

    public function down(): void
    {
        // Old code cannot represent mixed rows. Never silently discard configured staffing.
        if (DB::table('coverage_staffing')->select('phase_id')->groupBy('phase_id')->havingRaw('COUNT(DISTINCT model) > 1')->exists()) {
            throw new RuntimeException('Resolve mixed phase staffing before rolling back coverage support.');
        }
        Schema::table('phase_coverages', fn (Blueprint $table) => $table->string('model', 16)->default('dedicated'));
        foreach (DB::table('phase_coverages')->get() as $phase) {
            $model = DB::table('coverage_staffing')->where('phase_id', $phase->phase_id)->value('model')
                ?? (DB::table('coverage_groups')->where('phase_id', $phase->phase_id)->exists() ? 'shared' : 'dedicated');
            DB::table('phase_coverages')->where('phase_id', $phase->phase_id)->update(['model' => $model]);
        }
        Schema::table('phase_coverages', fn (Blueprint $table) => $table->unique(['phase_id', 'model']));
        Schema::table('coverage_staffing', function (Blueprint $table) {
            $table->dropForeign(['phase_id']);
            $table->foreign(['phase_id', 'model'])->references(['phase_id', 'model'])->on('phase_coverages')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE phase_coverages DROP CONSTRAINT phase_coverages_revision_check');
            DB::statement("ALTER TABLE phase_coverages ADD CONSTRAINT phase_coverages_check CHECK (model IN ('dedicated', 'shared') AND revision > 0)");
        }
    }
};
