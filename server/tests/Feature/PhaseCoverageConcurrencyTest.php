<?php

use App\Models\OrganizationMembership;
use App\Services\Seasons\SaveSeason;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

it('serializes two managers creating coverage for the same phase on PostgreSQL', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL row locks and independent committed connections.');
    }
    $manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $peer = OrganizationMembership::factory()->create(['role' => 'management', 'organization_id' => $manager->organization_id]);
    $season = app(SaveSeason::class)->handle($manager->user, $manager->organization, (string) Str::uuid(), [
        'name' => 'Season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'timezone' => 'America/Los_Angeles',
        'week_starts_on' => 1, 'expected_revision' => null, 'phases' => [['name' => 'Phase', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']],
    ]);
    $input = ['expected_revision' => null, 'expected_season_revision' => 1, 'model' => 'dedicated', 'reason' => 'Configure',
        'shifts' => [['name' => 'Night', 'start_time' => '22:00', 'duration_minutes' => 480]],
        'areas' => [['name' => 'North']], 'groups' => [], 'staffing' => [['shift_name' => 'Night', 'area_name' => 'North', 'positions' => 1]]];
    $code = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $args = json_decode(base64_decode($argv[1]), true);
    Illuminate\Support\Facades\DB::statement("set application_name to 'rush-coverage-concurrency'");
    try {
        app(App\Services\Seasons\SavePhaseCoverage::class)->handle(
            App\Models\User::findOrFail($args['actor']), App\Models\Organization::findOrFail($args['organization']), $args['phase'], $args['input']);
        echo 'accepted';
    } catch (App\Exceptions\RevisionConflict) {
        echo 'conflict';
    }
    PHP;
    $processes = [];
    DB::beginTransaction();
    DB::table('organizations')->where('id', $manager->organization_id)->lockForUpdate()->first();
    try {
        foreach ([$manager, $peer] as $actor) {
            $args = ['actor' => $actor->user_id, 'organization' => $manager->organization_id, 'phase' => $season->phases->first()->id, 'input' => $input];
            $process = new Process([PHP_BINARY, '-r', $code, base64_encode(json_encode($args))], base_path(), timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = 'rush-coverage-concurrency' and wait_event_type = 'Lock'")->count;
            if ((int) $waiting === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect((int) $waiting)->toBe(2);
    } finally {
        DB::rollBack();
    }
    $results = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $results[] = $process->getOutput();
    }
    expect($results)->toContain('accepted', 'conflict');
    $this->assertDatabaseCount('phase_coverages', 1);
    $this->assertDatabaseCount('phase_coverage_changes', 1);
    $this->assertDatabaseCount('coverage_staffing', 1);
});
