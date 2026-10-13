<?php

use App\Models\OrganizationMembership;
use App\Models\PreferenceProfile;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

it('serializes simultaneous first preference profiles on PostgreSQL', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL row locks and independent committed connections.');
    }
    $manager = OrganizationMembership::factory()->create(['role' => 'ranger']);
    $peer = $manager;
    $input = ['membership_id' => $manager->id, 'season_id' => null, 'week_starts_on' => null,
        'preferred_shift_ids' => [], 'hours_mode' => 'none', 'desired_weekly_minutes' => null, 'days_off' => 'none'];
    $code = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $args = json_decode(base64_decode($argv[1]), true);
    Illuminate\Support\Facades\DB::statement("set application_name to 'rush-preference-concurrency'");
    try {
        app(App\Services\Preferences\SavePreferenceProfile::class)->handle(
            App\Models\User::findOrFail($args['actor']), App\Models\OrganizationMembership::findOrFail($args['membership']), $args['id'], null, $args['input']);
        echo 'accepted';
    } catch (App\Exceptions\RevisionConflict) {
        echo 'conflict';
    }
    PHP;
    $processes = [];
    DB::beginTransaction();
    DB::table('organization_memberships')->where('id', $manager->id)->lockForUpdate()->first();
    try {
        foreach ([$manager, $peer] as $index => $actor) {
            $args = ['actor' => $actor->user_id, 'membership' => $manager->id, 'id' => (string) Str::uuid(),
                'input' => $input];
            $process = new Process([PHP_BINARY, '-r', $code, base64_encode(json_encode($args))], base_path(), timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = 'rush-preference-concurrency' and wait_event_type = 'Lock'")->count;
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
    expect(PreferenceProfile::query()->sole()->revision)->toBe(1);
    $this->assertDatabaseCount('preference_profile_changes', 1);
});
