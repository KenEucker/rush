<?php

use App\Models\OrganizationMembership;
use App\Models\Season;
use App\Services\Seasons\SaveSeason;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

it('serializes competing calendar changes by different managers on PostgreSQL', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL row locks and independent committed connections.');
    }
    $manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $peer = OrganizationMembership::factory()->create(['role' => 'management', 'organization_id' => $manager->organization_id]);
    $id = (string) Str::uuid();
    $input = ['name' => 'Season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        'timezone' => 'America/Los_Angeles', 'week_starts_on' => 1, 'expected_revision' => null, 'reason' => 'Create', 'phases' => []];
    app(SaveSeason::class)->handle($manager->user, $manager->organization, $id, $input);
    $code = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $args = json_decode(base64_decode($argv[1]), true);
    Illuminate\Support\Facades\DB::statement("set application_name to 'rush-season-concurrency'");
    try {
        app(App\Services\Seasons\SaveSeason::class)->handle(
            App\Models\User::findOrFail($args['actor']), App\Models\Organization::findOrFail($args['organization']), $args['id'], $args['input']);
        echo 'accepted';
    } catch (App\Exceptions\RevisionConflict) {
        echo 'conflict';
    }
    PHP;
    $processes = [];
    DB::beginTransaction();
    DB::table('organizations')->where('id', $manager->organization_id)->lockForUpdate()->first();
    try {
        foreach ([$manager, $peer] as $index => $actor) {
            $args = ['actor' => $actor->user_id, 'organization' => $manager->organization_id, 'id' => $id,
                'input' => [...$input, 'expected_revision' => 1, 'week_starts_on' => $index + 2]];
            $process = new Process([PHP_BINARY, '-r', $code, base64_encode(json_encode($args))], base_path(), timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = 'rush-season-concurrency' and wait_event_type = 'Lock'")->count;
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
    expect(Season::findOrFail($id)->revision)->toBe(2);
    $this->assertDatabaseCount('season_changes', 2);
});
