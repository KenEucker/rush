<?php

use App\Models\OrganizationMembership;
use App\Services\Scheduling\PlanningInputs;
use App\Services\Scheduling\ScheduleDrafts;
use App\Services\Seasons\SaveSeason;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

it('accepts exactly one simultaneous manager edit of a PostgreSQL draft revision', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL row locks and independent processes.');
    }
    $manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $peer = OrganizationMembership::factory()->create(['role' => 'management', 'organization_id' => $manager->organization_id]);
    $season = app(SaveSeason::class)->handle($manager->user, $manager->organization, (string) Str::uuid(), [
        'name' => 'Concurrency', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'timezone' => 'UTC',
        'week_starts_on' => 1, 'expected_revision' => null, 'phases' => []]);
    $snapshot = app(PlanningInputs::class)->capture($manager->user, $manager->organization, $season->id,
        ['starts_on' => '2026-06-01', 'ends_on' => '2026-06-01', 'reason' => 'Concurrent editing']);
    $draft = app(ScheduleDrafts::class)->generate($manager->user, $manager->organization, $snapshot['id'], ['reason' => 'Empty proposal']);
    $code = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $args = json_decode(base64_decode($argv[1]), true);
    Illuminate\Support\Facades\DB::statement("set application_name to 'rush-draft-concurrency'");
    try {
        app(App\Services\Scheduling\ScheduleDrafts::class)->save(
            App\Models\User::findOrFail($args['actor']), App\Models\Organization::findOrFail($args['organization']), $args['draft'],
            ['expected_revision' => 1, 'reason' => 'Concurrent review', 'candidates' => []]);
        echo 'accepted';
    } catch (App\Exceptions\RevisionConflict) {
        echo 'conflict';
    }
    PHP;
    $processes = [];
    DB::beginTransaction();
    DB::table('schedule_drafts')->where('id', $draft['id'])->lockForUpdate()->first();
    try {
        foreach ([$manager, $peer] as $actor) {
            $args = ['actor' => $actor->user_id, 'organization' => $manager->organization_id, 'draft' => $draft['id']];
            $process = new Process([PHP_BINARY, '-r', $code, base64_encode(json_encode($args))], base_path(), timeout: 30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = 'rush-draft-concurrency' and wait_event_type = 'Lock'")->count;
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
    expect(DB::table('schedule_drafts')->find($draft['id'])->revision)->toBe(2);
    $this->assertDatabaseCount('draft_changes', 2);
});
