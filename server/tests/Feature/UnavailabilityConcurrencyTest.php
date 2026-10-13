<?php

use App\Models\OrganizationMembership;
use App\Services\Availability\SaveUnavailability;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

it('serializes Management and Ranger edits or duplicate acknowledgments on PostgreSQL', function (bool $acknowledge) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL row locks and independent committed connections.');
    }
    $ranger = OrganizationMembership::factory()->create(['role' => 'ranger']);
    $manager = OrganizationMembership::factory()->create(['organization_id' => $ranger->organization_id, 'role' => 'management']);
    $peer = OrganizationMembership::factory()->create(['organization_id' => $ranger->organization_id, 'role' => 'management']);
    $id = (string) Str::uuid();
    $input = ['starts_at' => '2026-11-01T07:00:00Z', 'ends_at' => '2026-11-01T12:00:00Z', 'reason' => 'Emergency'];
    app(SaveUnavailability::class)->handle($ranger->user, $ranger, $id, null, $input);
    $code = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $args = json_decode(base64_decode($argv[1]), true);
    Illuminate\Support\Facades\DB::statement("set application_name to 'rush-availability-concurrency'");
    try {
        $actor = App\Models\User::findOrFail($args['actor']);
        $ranger = App\Models\OrganizationMembership::findOrFail($args['ranger']);
        if ($args['acknowledge']) {
            app(App\Services\Availability\ManageUnavailability::class)->acknowledge($actor, $ranger->organization, $args['id'], ['expected_revision' => 1]);
        } else {
            app(App\Services\Availability\SaveUnavailability::class)->handle($actor, $ranger, $args['id'], 1, $args['input']);
        }
        echo 'accepted';
    } catch (App\Exceptions\RevisionConflict) {
        echo 'conflict';
    }
    PHP;
    $processes = [];
    DB::beginTransaction();
    DB::table('organization_memberships')->where('id', $ranger->id)->lockForUpdate()->first();
    try {
        foreach ([$manager, $acknowledge ? $peer : $ranger] as $actor) {
            $args = ['actor' => $actor->user_id, 'ranger' => $ranger->id, 'id' => $id, 'input' => $input, 'acknowledge' => $acknowledge];
            $process = new Process([PHP_BINARY, '-r', $code, base64_encode(json_encode($args))], base_path(), timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = 'rush-availability-concurrency' and wait_event_type = 'Lock'")->count;
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
    if ($acknowledge) {
        expect($results)->toBe(['accepted', 'accepted']);
        $this->assertDatabaseCount('unavailability_acknowledgments', 1);
        $this->assertDatabaseCount('unavailability_changes', 1);
    } else {
        expect($results)->toContain('accepted', 'conflict');
        $this->assertDatabaseCount('unavailability_changes', 2);
    }
})->with([true, false]);
