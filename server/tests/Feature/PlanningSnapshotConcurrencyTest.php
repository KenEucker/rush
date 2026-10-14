<?php

use App\Models\OrganizationMembership;
use App\Services\Scheduling\PlanningInputs;
use App\Services\Seasons\SaveSeason;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

it('captures one committed PostgreSQL snapshot while independent sources change concurrently', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL repeatable-read MVCC and independent committed connections.');
    }
    $manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $ranger = OrganizationMembership::factory()->create(['organization_id' => $manager->organization_id, 'role' => 'ranger']);
    $season = app(SaveSeason::class)->handle($manager->user, $manager->organization, (string) Str::uuid(), [
        'name' => 'Concurrency', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'timezone' => 'UTC',
        'week_starts_on' => 1, 'expected_revision' => null, 'phases' => []]);
    config(['database.connections.planning_peer' => config('database.connections.'.DB::getDefaultConnection())]);
    $changed = false;
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$changed, &$armed, $ranger) {
        if (! $armed || $changed || ! str_contains($query->sql, '"organization_memberships"') || ! str_contains($query->sql, 'order by "id"')) {
            return;
        }
        $changed = true;
        DB::connection('planning_peer')->transaction(function ($peer) use ($ranger) {
            $peer->table('organization_memberships')->where('id', $ranger->id)->update(['is_active' => false]);
            $peer->table('unavailabilities')->insert(['id' => (string) Str::uuid(), 'organization_id' => $ranger->organization_id,
                'organization_membership_id' => $ranger->id, 'starts_at' => '2026-06-01 08:00:00+00', 'ends_at' => '2026-06-01 16:00:00+00', 'revision' => 1]);
        });
    });
    try {
        $record = app(PlanningInputs::class)->capture($manager->user, $manager->organization, $season->id,
            ['starts_on' => '2026-06-01', 'ends_on' => '2026-06-01', 'reason' => 'Concurrency proof']);
        expect($changed)->toBeTrue()->and($record['inputs']['unavailability'])->toBe([])
            ->and(collect($record['inputs']['roster'])->firstWhere('id', $ranger->id)['is_active'])->toBeTrue();
        expect(app(PlanningInputs::class)->read($manager->user, $manager->organization, $record['id'])['stale'])->toBeTrue();
    } finally {
        $armed = false;
        DB::disconnect('planning_peer');
    }
});
