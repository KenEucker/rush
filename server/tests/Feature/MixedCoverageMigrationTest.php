<?php

use App\Models\OrganizationMembership;
use App\Services\Seasons\SavePhaseCoverage;
use App\Services\Seasons\SaveSeason;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $season = app(SaveSeason::class)->handle($this->manager->user, $this->manager->organization, (string) Str::uuid(), [
        'name' => 'Migration proof', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        'timezone' => 'UTC', 'week_starts_on' => 1, 'expected_revision' => null,
        'phases' => [['name' => 'Phase', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']],
    ]);
    $this->phase = $season->phases->first();
    $this->input = ['expected_revision' => null, 'expected_season_revision' => 1, 'reason' => 'Configure',
        'shifts' => [['name' => 'Evening', 'start_time' => '18:00', 'duration_minutes' => 480]],
        'areas' => [['name' => 'North'], ['name' => 'South']], 'groups' => [],
        'staffing' => [['shift_name' => 'Evening', 'area_name' => 'North', 'positions' => 2]]];
    $this->migration = require database_path('migrations/2026_10_13_210000_allow_mixed_phase_coverage.php');
});

it('upgrades existing single-model coverage without changing its records and round trips rollback', function (string $model) {
    $input = $this->input;
    if ($model === 'shared') {
        $input['groups'] = [['name' => 'Joint', 'area_names' => ['North', 'South']]];
        $input['staffing'] = [['shift_name' => 'Evening', 'group_name' => 'Joint', 'positions' => 1]];
    }
    app(SavePhaseCoverage::class)->handle($this->manager->user, $this->manager->organization, $this->phase->id, $input);
    $tables = ['coverage_staffing', 'shift_definitions', 'coverage_areas', 'coverage_groups', 'coverage_group_areas', 'phase_coverage_changes'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()]);
    $this->migration->down();
    expect(DB::table('phase_coverages')->value('model'))->toBe($model);
    $this->migration->up();
    expect(Schema::hasColumn('phase_coverages', 'model'))->toBeFalse();
    expect(DB::table('phase_coverages')->value('revision'))->toBe(1);
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
})->with(['dedicated', 'shared']);

it('refuses downgrade of mixed staffing before altering schema or data', function () {
    app(SavePhaseCoverage::class)->handle($this->manager->user, $this->manager->organization, $this->phase->id,
        [...$this->input, 'groups' => [['name' => 'Joint', 'area_names' => ['South']]],
            'staffing' => [...$this->input['staffing'], ['shift_name' => 'Evening', 'group_name' => 'Joint', 'positions' => 1]]]);
    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, 'Resolve mixed phase staffing');
    expect(Schema::hasColumn('phase_coverages', 'model'))->toBeFalse();
    $this->assertDatabaseCount('coverage_staffing', 2);
});

it('retains PostgreSQL exclusive-target and phase ownership constraints for mixed staffing', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL check constraints.');
    }
    $saved = app(SavePhaseCoverage::class)->handle($this->manager->user, $this->manager->organization, $this->phase->id,
        [...$this->input, 'groups' => [['name' => 'Joint', 'area_names' => ['South']]],
            'staffing' => [...$this->input['staffing'], ['shift_name' => 'Evening', 'group_name' => 'Joint', 'positions' => 1]]]);
    $row = DB::table('coverage_staffing')->where('model', 'dedicated')->first();
    foreach ([['group_id' => $saved['groups'][0]['id']], ['area_id' => null], ['model' => 'shared'],
        ['positions' => -1], ['phase_id' => (string) Str::uuid()]] as $fields) {
        expect(fn () => DB::transaction(fn () => DB::table('coverage_staffing')->where('id', $row->id)->update($fields)))
            ->toThrow(QueryException::class);
    }
    $this->assertDatabaseCount('coverage_staffing', 2);
});
