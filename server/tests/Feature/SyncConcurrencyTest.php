<?php

use App\Models\MemberProfile;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

it('serializes concurrent duplicate and competing device commands on PostgreSQL', function (bool $duplicate) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL row locks and separate committed connections.');
    }
    $profile = MemberProfile::factory()->create();
    $operation = ['operation_id' => (string) Str::uuid(), 'type' => 'member_profile.update',
        'record_id' => $profile->id, 'expected_revision' => 1, 'payload' => ['display_name' => 'Concurrent edit']];
    $code = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $input = json_decode(base64_decode($argv[1]), true);
    Illuminate\Support\Facades\DB::statement("set application_name to 'rush-sync-concurrency'");
    $result = app(App\Services\Sync\PushOperation::class)->handle(
        App\Models\User::findOrFail($input['actor']),
        App\Models\Organization::findOrFail($input['organization']), $input['operation']);
    echo json_encode($result);
    PHP;
    $processes = [];
    DB::beginTransaction();
    DB::table('organization_memberships')->where('id', $profile->organization_membership_id)->lockForUpdate()->first();
    try {
        foreach ([0, 1] as $index) {
            $input = ['actor' => $profile->membership->user_id, 'organization' => $profile->organization_id,
                'operation' => [...$operation, 'operation_id' => $duplicate || $index === 0 ? $operation['operation_id'] : (string) Str::uuid()]];
            $process = new Process([PHP_BINARY, '-r', $code, base64_encode(json_encode($input))], base_path(), timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = 'rush-sync-concurrency' and wait_event_type = 'Lock'")->count;
            if ((int) $waiting === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect((int) $waiting)->toBe(2, 'Both independent writers must reach the membership lock.');
    } finally {
        DB::rollBack();
    }
    $results = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    if ($duplicate) {
        expect($results[0])->toBe($results[1]);
        $this->assertDatabaseCount('sync_operations', 1);
    } else {
        expect(array_column($results, 'status'))->toContain('accepted', 'conflict');
        $this->assertDatabaseCount('sync_operations', 2);
    }
    expect($profile->fresh()->revision)->toBe(2);
    $this->assertDatabaseCount('member_profile_changes', 1);
})->with([true, false]);
