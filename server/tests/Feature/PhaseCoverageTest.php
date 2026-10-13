<?php

use App\Models\OrganizationMembership;
use App\Services\Seasons\SavePhaseCoverage;
use App\Services\Seasons\SaveSeason;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->seasonInput = ['name' => 'Season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        'timezone' => 'America/Los_Angeles', 'week_starts_on' => 1, 'expected_revision' => null,
        'phases' => [['name' => 'Low staffing', 'starts_on' => '2026-01-01', 'ends_on' => '2026-05-31'],
            ['name' => 'Full staffing', 'starts_on' => '2026-06-01', 'ends_on' => '2026-12-31']]];
    $this->season = app(SaveSeason::class)->handle($this->manager->user, $this->manager->organization, (string) Str::uuid(), $this->seasonInput);
    $this->phase = $this->season->phases->first();
    $this->base = '/api/v1/organizations/'.$this->manager->organization_id;
    $this->url = $this->base.'/phases/'.$this->phase->id.'/coverage';
    $this->input = ['expected_revision' => null, 'expected_season_revision' => 1, 'reason' => 'Initial coverage',
        'shifts' => [['name' => 'Evening', 'start_time' => '21:30', 'duration_minutes' => 390]],
        'areas' => [['name' => 'North'], ['name' => 'South']], 'groups' => [],
        'staffing' => [['shift_name' => 'Evening', 'area_name' => 'North', 'positions' => 2],
            ['shift_name' => 'Evening', 'area_name' => 'South', 'positions' => 0]]];
});

it('saves configurable dedicated coverage and atomically switches to shared coverage with an audit', function () {
    $this->actingAs($this->manager->user);
    $this->getJson($this->url)->assertOk()->assertJsonPath('revision', null);
    $saved = $this->putJson($this->url, $this->input)->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('shifts.0.duration_minutes', 390)->json();
    $shared = [...$saved, 'expected_revision' => 1, 'expected_season_revision' => 1, 'model' => 'shared', 'reason' => 'Joint patrol',
        'groups' => [['name' => 'Both areas', 'area_names' => ['North', 'South']]],
        'staffing' => [['shift_name' => 'Evening', 'group_name' => 'Both areas', 'positions' => 1]]];
    $this->putJson($this->url, $shared)->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('groups.0.area_names', ['North', 'South']);
    $this->putJson($this->url, $shared)->assertConflict();
    $this->getJson($this->url)->assertOk()->assertJsonPath('model', 'shared')->assertJsonPath('shifts.0.id', $saved['shifts'][0]['id']);
    $this->assertDatabaseCount('coverage_staffing', 1);
    $audit = DB::table('phase_coverage_changes')->where('revision', 2)->first();
    expect($audit->actor_id)->toBe($this->manager->user_id)
        ->and(json_decode($audit->before, true)['model'])->toBe('dedicated')
        ->and(json_decode($audit->after, true)['model'])->toBe('shared');
    // Switching back removes shared configuration as one transaction.
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 2])->assertOk()->assertJsonPath('revision', 3);
    $this->assertDatabaseCount('coverage_groups', 0);
    $this->assertDatabaseCount('coverage_group_areas', 0);
});

it('keeps different phase models and three configurable daily shifts independent', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertOk();
    $otherUrl = $this->base.'/phases/'.$this->season->phases->last()->id.'/coverage';
    $shifts = [['name' => 'Morning', 'start_time' => '06:00', 'duration_minutes' => 480],
        ['name' => 'Afternoon', 'start_time' => '14:00', 'duration_minutes' => 480],
        ['name' => 'Night', 'start_time' => '22:00', 'duration_minutes' => 480]];
    $this->putJson($otherUrl, [...$this->input, 'model' => 'shared', 'shifts' => $shifts,
        'groups' => [['name' => 'Patrol', 'area_names' => ['North', 'South']]],
        'staffing' => array_map(fn ($shift) => ['shift_name' => $shift['name'], 'group_name' => 'Patrol', 'positions' => 2], $shifts)])
        ->assertOk()->assertJsonCount(3, 'shifts')->assertJsonCount(3, 'staffing');
    $this->getJson($this->url)->assertJsonPath('model', 'dedicated')->assertJsonPath('revision', 1);
});

it('rejects invalid configuration without partial writes', function (string $field, mixed $value) {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertOk();
    $changed = [...$this->input, 'expected_revision' => 1];
    data_set($changed, $field, $value);
    $this->putJson($this->url, $changed)->assertUnprocessable();
    $this->getJson($this->url)->assertJsonPath('revision', 1)->assertJsonPath('shifts.0.start_time', '21:30');
    $this->assertDatabaseCount('phase_coverage_changes', 1);
    $this->assertDatabaseCount('coverage_staffing', 2);
})->with([
    ['shifts.0.start_time', '24:00'], ['shifts.0.duration_minutes', 0], ['shifts.0.duration_minutes', 7.5],
    ['shifts', []], ['areas', []], ['reason', ''],
    ['areas.1.name', 'north'], ['staffing.0.positions', -1], ['staffing.0.positions', 1.5],
    ['staffing.0.shift_name', 'Missing'], ['staffing.0.area_name', 'Foreign area'],
    ['staffing.0.group_name', 'Mixed'], ['staffing.1.area_name', 'North'],
    ['staffing.0.area_name', null], ['shifts.0.desirability', 'undesirable'],
]);

it('validates shared group members even when staffing uses dedicated areas', function () {
    $shared = [...$this->input, 'model' => 'shared', 'groups' => [['name' => 'Joint', 'area_names' => ['North', 'South']]],
        'staffing' => [['shift_name' => 'Evening', 'group_name' => 'Joint', 'positions' => 2]]];
    $this->actingAs($this->manager->user);
    foreach ([[], ['North', 'North'], ['Foreign']] as $members) {
        $bad = $shared;
        $bad['groups'][0]['area_names'] = $members;
        $this->putJson($this->url, $bad)->assertUnprocessable();
        $this->putJson($this->url, [...$bad, 'staffing' => $this->input['staffing']])->assertUnprocessable();
    }
    $this->assertDatabaseCount('phase_coverages', 0);
    $this->assertDatabaseCount('shift_definitions', 0);
    $this->putJson($this->url, $shared)->assertOk();
});

it('saves mixed staffing through Orchid and API while retaining identities counts and audit history', function () {
    $this->actingAs($this->manager->user);
    $saved = $this->putJson($this->url, $this->input)->assertOk()->json();
    $mixed = [...$saved, 'expected_revision' => 1, 'expected_season_revision' => 1, 'reason' => 'Add shared patrol',
        'groups' => [['name' => 'Joint', 'area_names' => ['North', 'South']]],
        'staffing' => [...$saved['staffing'], ['shift_name' => 'Evening', 'group_name' => 'Joint', 'positions' => 1]]];
    $admin = '/admin/organizations/'.$this->manager->organization_id.'/phases/'.$this->phase->id.'/coverage';
    $this->post($admin.'/save', ['configuration' => $mixed])->assertRedirect($admin)->assertSessionHasNoErrors();
    $snapshot = $this->getJson($this->url)->assertOk()->assertJsonPath('model', 'mixed')->assertJsonPath('revision', 2)
        ->assertJsonPath('shifts.0.id', $saved['shifts'][0]['id'])->assertJsonPath('areas', $saved['areas'])->assertJsonCount(3, 'staffing')->json();
    expect(collect($snapshot['staffing'])->pluck('positions')->sort()->values()->all())->toBe([0, 1, 2]);
    $audit = DB::table('phase_coverage_changes')->where('revision', 2)->first();
    expect(json_decode($audit->before, true)['model'])->toBe('dedicated')
        ->and(json_decode($audit->after, true)['model'])->toBe('mixed');
    $this->putJson($this->url, $mixed)->assertConflict();
    // Definitions are allowed before their staffing counts are configured.
    $this->putJson($this->url, [...$mixed, 'expected_revision' => 2, 'staffing' => []])->assertOk()
        ->assertJsonPath('model', null)->assertJsonCount(1, 'groups');
});

it('rejects both targets neither target unknown references and duplicate shared rows atomically', function () {
    $this->actingAs($this->manager->user);
    $input = [...$this->input, 'groups' => [['name' => 'Joint', 'area_names' => ['South']]],
        'staffing' => [...$this->input['staffing'], ['shift_name' => 'Evening', 'group_name' => 'Joint', 'positions' => 1]]];
    $this->putJson($this->url, $input)->assertOk();
    foreach ([['area_name' => 'North', 'group_name' => 'Joint'], ['area_name' => null, 'group_name' => null],
        ['group_name' => 'Foreign'], ['group_name' => 'Joint', 'shift_name' => 'Foreign'], ['group_name' => 'Joint']] as $target) {
        $bad = [...$input, 'expected_revision' => 1];
        $bad['staffing'][] = [...['shift_name' => 'Evening', 'positions' => 3], ...$target];
        $this->putJson($this->url, $bad)->assertUnprocessable()->assertJsonValidationErrors('staffing.3');
        $this->getJson($this->url)->assertJsonPath('revision', 1)->assertJsonPath('model', 'mixed');
        $this->assertDatabaseCount('coverage_staffing', 3);
        $this->assertDatabaseCount('phase_coverage_changes', 1);
    }
});

it('resolves each assignment target in a mixed phase and preserves its context after reconfiguration', function () {
    $this->actingAs($this->manager->user);
    // UUIDs belong to separate collections; explicit model disambiguates a rare collision.
    $targetId = (string) Str::uuid();
    $input = [...$this->input, 'areas' => [['id' => $targetId, 'name' => 'North'], ['name' => 'South']],
        'groups' => [['id' => $targetId, 'name' => 'Joint', 'area_names' => ['North', 'South']]],
        'staffing' => [...$this->input['staffing'], ['shift_name' => 'Evening', 'group_name' => 'Joint', 'positions' => 1]]];
    $saved = $this->putJson($this->url, $input)->assertOk()->json();
    $ranger = OrganizationMembership::factory()->create(['organization_id' => $this->manager->organization_id]);
    $assignment = ['membership_id' => $ranger->id, 'expected_revision' => null, 'reason' => 'Mixed coverage',
        'starts_at' => '2026-03-09T04:30:00Z', 'ends_at' => '2026-03-09T07:00:00Z',
        'shift' => ['phase_id' => $this->phase->id, 'shift_id' => $saved['shifts'][0]['id'], 'target_id' => $targetId,
            'date' => '2026-03-08', 'expected_coverage_revision' => 1, 'expected_season_revision' => 1]];
    $this->putJson($this->base.'/official-assignments/'.Str::uuid(), $assignment)->assertUnprocessable()->assertJsonValidationErrors('shift.model');
    foreach (['dedicated' => ['North'], 'shared' => ['North', 'South']] as $model => $areas) {
        $url = $this->base.'/official-assignments/'.Str::uuid();
        $this->putJson($url, [...$assignment, 'shift' => [...$assignment['shift'], 'model' => $model]])->assertOk()
            ->assertJsonPath('shift_context.model', $model)->assertJsonPath('shift_context.area_names', $areas);
    }
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1])->assertOk();
    unset($assignment['shift']);
    $this->putJson($url, [...$assignment, 'expected_revision' => 1])->assertOk()
        ->assertJsonPath('shift_context.model', 'shared')->assertJsonPath('shift_context.area_names', ['North', 'South']);
});

it('checks current management access on API and Orchid reads writes and direct service calls', function () {
    $admin = '/admin/organizations/'.$this->manager->organization_id.'/phases/'.$this->phase->id.'/coverage';
    $this->getJson($this->url)->assertUnauthorized();
    $this->putJson($this->url, $this->input)->assertUnauthorized();
    $ranger = OrganizationMembership::factory()->create(['organization_id' => $this->manager->organization_id]);
    $this->actingAs($ranger->user)->getJson($this->url)->assertForbidden();
    $this->putJson($this->url, $this->input)->assertForbidden();
    $this->get($admin)->assertForbidden();
    $this->post($admin.'/save', ['configuration' => $this->input])->assertForbidden();
    $foreign = OrganizationMembership::factory()->create(['role' => 'management']);
    Auth::forgetGuards();
    $this->actingAs($foreign->user)->getJson('/api/v1/organizations/'.$foreign->organization_id.'/phases/'.$this->phase->id.'/coverage')->assertNotFound();
    Auth::forgetGuards();
    $this->actingAs($this->manager->user, 'web')->get($admin)->assertOk()->assertSee('Save phase coverage');
    $this->post($admin.'/save', ['configuration' => $this->input])->assertRedirect($admin);
    $this->manager->update(['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
    expect(fn () => app(SavePhaseCoverage::class)->handle($this->manager->user, $this->manager->organization, $this->phase->id, $this->input))
        ->toThrow(AuthorizationException::class);
});

it('rejects stale season revisions and foreign row identities with atomic rollback', function () {
    $this->actingAs($this->manager->user);
    $saved = $this->putJson($this->url, $this->input)->assertOk()->json();
    $foreign = $this->input;
    $foreign['shifts'][0]['id'] = $saved['shifts'][0]['id'];
    $this->putJson($this->base.'/phases/'.$this->season->phases->last()->id.'/coverage', $foreign)->assertUnprocessable();
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'expected_season_revision' => 2])->assertConflict();
    $this->assertDatabaseCount('phase_coverages', 1);
    $this->assertDatabaseCount('phase_coverage_changes', 1);
});

it('accepts selected area arrays from Orchid and rejects empty or unknown selections without changing coverage', function () {
    $admin = '/admin/organizations/'.$this->manager->organization_id.'/phases/'.$this->phase->id.'/coverage';
    $shared = [...$this->input, 'model' => 'shared',
        'groups' => [['name' => 'Joint', 'area_names' => ['North', 'South']]],
        'staffing' => [['shift_name' => 'Evening', 'group_name' => 'Joint', 'positions' => 2]]];
    $this->actingAs($this->manager->user, 'web')->from($admin)
        ->post($admin.'/save', ['configuration' => $shared])->assertRedirect($admin)->assertSessionHasNoErrors();
    $this->getJson($this->url)->assertJsonPath('groups.0.area_names', ['North', 'South']);
    $shared['expected_revision'] = 1;
    foreach ([[], ['Missing'], 'North, South'] as $members) {
        $bad = $shared;
        $bad['groups'][0]['area_names'] = $members;
        $this->from($admin)->post($admin.'/save', ['configuration' => $bad])
            ->assertRedirect($admin)->assertSessionHasErrors('configuration.groups.0.area_names')
            ->assertSessionHasInput('configuration.groups.0.area_names', $members);
        $this->getJson($this->url)->assertJsonPath('revision', 1)->assertJsonPath('groups.0.area_names', ['North', 'South']);
    }
    $shared['groups'][0]['area_names'] = ['North'];
    $this->post($admin.'/save', ['configuration' => $shared])->assertRedirect($admin)->assertSessionHasNoErrors();
    $this->getJson($this->url)->assertJsonPath('revision', 2)->assertJsonPath('groups.0.area_names', ['North']);
});

it('rolls back all configuration when audit persistence fails', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertOk();
    DB::table('phase_coverage_changes')->insert(['id' => (string) Str::uuid(), 'phase_id' => $this->phase->id,
        'actor_id' => $this->manager->user_id, 'revision' => 2, 'reason' => 'Force collision', 'after' => '{}', 'occurred_at' => now()]);
    $this->withoutExceptionHandling();
    try {
        $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'staffing' => []]);
        test()->fail('Audit constraint should fail.');
    } catch (QueryException) {
        $this->assertDatabaseHas('phase_coverages', ['phase_id' => $this->phase->id, 'revision' => 1]);
        $this->assertDatabaseCount('coverage_staffing', 2);
    }
});

it('persists whole and partial manual assignments with immutable shift and area context', function () {
    $this->actingAs($this->manager->user);
    $saved = $this->putJson($this->url, $this->input)->assertOk()->json();
    $ranger = OrganizationMembership::factory()->create(['organization_id' => $this->manager->organization_id]);
    $url = $this->base.'/official-assignments/'.Str::uuid();
    $assignment = ['membership_id' => $ranger->id, 'expected_revision' => null, 'reason' => 'Manual partial',
        'starts_at' => '2026-03-09T04:30:00Z', 'ends_at' => '2026-03-09T07:00:00Z',
        'shift' => ['phase_id' => $this->phase->id, 'shift_id' => $saved['shifts'][0]['id'], 'target_id' => $saved['areas'][0]['id'],
            'date' => '2026-03-08', 'expected_coverage_revision' => 1, 'expected_season_revision' => 1]];
    $this->putJson($url, $assignment)->assertOk()->assertJsonPath('shift_context.is_partial', true)
        ->assertJsonPath('shift_context.ends_at', '2026-03-09T11:00:00Z')->assertJsonPath('shift_context.area_names', ['North']);
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'shifts' => [['name' => 'New night', 'start_time' => '20:00', 'duration_minutes' => 600]], 'staffing' => []])->assertOk();
    unset($assignment['shift']);
    $this->putJson($url, [...$assignment, 'expected_revision' => 1, 'ends_at' => '2026-03-09T11:00:00Z'])->assertOk()
        ->assertJsonPath('shift_context.is_partial', false)->assertJsonPath('shift_context.shift_name', 'Evening')->assertJsonPath('shift_context.coverage_revision', 1);
    $this->putJson($url, [...$assignment, 'expected_revision' => 2, 'ends_at' => '2026-03-09T11:01:00Z'])->assertUnprocessable();
    $this->assertDatabaseCount('official_assignment_changes', 2);
    expect(json_decode(DB::table('official_assignment_changes')->where('revision', 1)->value('after'), true)['shift_context']['is_partial'])->toBeTrue();
});

it('resolves shift starts through the season DST policy and measures elapsed duration', function (string $date, string $time, ?string $offset, string $start, string $end, int $status) {
    $this->actingAs($this->manager->user);
    $phase = str_starts_with($date, '2026-11') ? $this->season->phases->last() : $this->phase;
    $saved = $this->putJson($this->base.'/phases/'.$phase->id.'/coverage', [...$this->input,
        'shifts' => [['name' => 'Evening', 'start_time' => $time, 'duration_minutes' => 480]],
        'model' => 'shared', 'groups' => [['name' => 'Joint', 'area_names' => ['North', 'South']]],
        'staffing' => [['shift_name' => 'Evening', 'group_name' => 'Joint', 'positions' => 2]]])->assertOk()->json();
    $ranger = OrganizationMembership::factory()->create(['organization_id' => $this->manager->organization_id]);
    $response = $this->putJson($this->base.'/official-assignments/'.Str::uuid(), [
        'membership_id' => $ranger->id, 'expected_revision' => null, 'reason' => 'Whole shift', 'starts_at' => $start, 'ends_at' => $end,
        'shift' => ['phase_id' => $phase->id, 'shift_id' => $saved['shifts'][0]['id'], 'target_id' => $saved['groups'][0]['id'],
            'date' => $date, 'offset' => $offset, 'expected_coverage_revision' => 1, 'expected_season_revision' => 1],
    ])->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('shift_context.starts_at', $start)->assertJsonPath('shift_context.ends_at', $end)
            ->assertJsonPath('shift_context.is_partial', false)->assertJsonPath('shift_context.area_names', ['North', 'South']);
    } else {
        $this->assertDatabaseCount('official_assignments', 0);
        $this->assertDatabaseCount('official_assignment_changes', 0);
    }
})->with([
    ['2026-03-07', '22:00', null, '2026-03-08T06:00:00Z', '2026-03-08T14:00:00Z', 200],
    ['2026-03-08', '02:30', null, '2026-03-08T10:30:00Z', '2026-03-08T18:30:00Z', 422],
    ['2026-11-01', '01:30', null, '2026-11-01T08:30:00Z', '2026-11-01T16:30:00Z', 422],
    ['2026-11-01', '01:30', '-07:00', '2026-11-01T08:30:00Z', '2026-11-01T16:30:00Z', 200],
    ['2026-11-01', '01:30', '-08:00', '2026-11-01T09:30:00Z', '2026-11-01T17:30:00Z', 200],
    ['2026-11-01', '01:30', '-06:00', '2026-11-01T09:30:00Z', '2026-11-01T17:30:00Z', 422],
]);

it('revalidates assignment configuration revisions dates targets and partial bounds', function () {
    $this->actingAs($this->manager->user);
    $saved = $this->putJson($this->url, $this->input)->assertOk()->json();
    $ranger = OrganizationMembership::factory()->create(['organization_id' => $this->manager->organization_id]);
    $assignment = ['membership_id' => $ranger->id, 'expected_revision' => null, 'reason' => 'Partial',
        'starts_at' => '2026-03-09T04:30:00Z', 'ends_at' => '2026-03-09T07:00:00Z',
        'shift' => ['phase_id' => $this->phase->id, 'shift_id' => $saved['shifts'][0]['id'], 'target_id' => $saved['areas'][0]['id'],
            'date' => '2026-03-08', 'expected_coverage_revision' => 1, 'expected_season_revision' => 1]];
    foreach ([['shift.expected_coverage_revision', 2, 409], ['shift.expected_season_revision', 2, 409],
        ['shift.model', 'shared', 422], ['shift.model', 'mixed', 422],
        ['shift.shift_id', (string) Str::uuid(), 422], ['shift.target_id', (string) Str::uuid(), 422],
        ['shift.date', '2026-06-01', 422], ['starts_at', '2026-03-09T04:29:00Z', 422],
        ['ends_at', '2026-03-09T11:01:00Z', 422], ['shift', null, 422]] as [$field, $value, $status]) {
        $bad = $assignment;
        data_set($bad, $field, $value);
        $this->putJson($this->base.'/official-assignments/'.Str::uuid(), $bad)->assertStatus($status);
    }
    $this->assertDatabaseCount('official_assignments', 0);
});
