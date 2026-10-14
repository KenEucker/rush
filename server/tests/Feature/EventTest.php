<?php

use App\Models\OfficialAssignment;
use App\Models\OrganizationMembership;
use App\Models\Unavailability;
use App\Services\Events\SaveEvent;
use App\Services\Seasons\SaveSeason;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->rangers = OrganizationMembership::factory()->count(3)->create(['organization_id' => $this->manager->organization_id]);
    $this->season = app(SaveSeason::class)->handle($this->manager->user, $this->manager->organization, (string) Str::uuid(), [
        'name' => 'Season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'timezone' => 'America/Los_Angeles',
        'week_starts_on' => 1, 'expected_revision' => null, 'phases' => [['name' => 'Full', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']],
    ]);
    $this->id = (string) Str::uuid();
    $this->base = '/api/v1/organizations/'.$this->manager->organization_id.'/seasons/'.$this->season->id.'/events';
    $this->url = $this->base.'/'.$this->id;
    $this->input = ['expected_revision' => null, 'expected_season_revision' => 1, 'name' => 'Monday Meeting',
        'starts_local' => '2026-10-12 18:00:00', 'ends_local' => '2026-10-12 19:00:00', 'paid' => true,
        'target_type' => 'percentage', 'target_value' => 50, 'counts_scheduled' => false,
        'attendance_membership_ids' => [$this->rangers[0]->id], 'reason' => 'One-off meeting'];
});

it('links event management from the management home only for authorized organizations', function () {
    $hiddenOrganizations = [];
    foreach ([['role' => 'ranger'], ['role' => 'management', 'is_active' => false]] as $attributes) {
        $membership = OrganizationMembership::factory()->create(['user_id' => $this->manager->user_id, ...$attributes]);
        $hiddenOrganizations[] = $membership->organization->name;
    }
    $response = $this->actingAs($this->manager->user)->get('/admin/main')->assertOk()
        ->assertSee('Events and attendance')
        ->assertSee('Shifts and coverage')->assertSee('Scheduling policies')->assertSee('Unavailability notices')
        ->assertSee(route('platform.phase-coverage', [$this->manager->organization_id, $this->season->phases->first()->id]), false)
        ->assertSee(route('platform.phase-policies', [$this->manager->organization_id, $this->season->phases->first()->id]), false)
        ->assertSee(route('platform.events', [$this->manager->organization_id, $this->season->id, 'new']), false);
    foreach ($hiddenOrganizations as $name) {
        $response->assertDontSee($name);
    }
    $this->actingAs($this->rangers[0]->user)->get('/admin/main')->assertForbidden();
});

it('guides managers without a season to create one before configuring events', function () {
    $manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->actingAs($manager->user)->get('/admin/main')->assertOk()
        ->assertSee('Create a season to configure events')
        ->assertSee(route('platform.seasons', [$manager->organization_id, 'new']), false)
        ->assertDontSee(route('platform.events', [$this->manager->organization_id, $this->season->id, 'new']), false);
});

it('creates one dated paid event with stable attendance identities and actor-attributed history', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertCreated()
        ->assertJsonPath('starts_at', '2026-10-13T01:00:00Z')->assertJsonPath('revision', 1)
        ->assertJsonPath('evaluation.required', 2)->assertJsonPath('evaluation.maximum_shortfall', 1)
        ->assertJsonPath('evaluation.paid_attendance.0.allocations.0.week_starts_on', '2026-10-12')
        ->assertJsonPath('evaluation.paid_attendance.0.allocations.0.elapsed_seconds', 3600);
    $attendance = DB::table('event_attendances')->first()->id;
    $this->getJson($this->base)->assertOk()->assertJsonCount(1, 'data');
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'attendance_membership_ids' => [], 'reason' => 'Remove attendance'])->assertOk()->assertJsonCount(0, 'attendance');
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 2, 'paid' => false])->assertOk()->assertJsonCount(0, 'evaluation.paid_attendance');
    expect(DB::table('event_attendances')->first()->id)->toBe($attendance);
    $audit = DB::table('event_changes')->where('revision', 2)->first();
    expect($audit->actor_id)->toBe($this->manager->user_id)
        ->and(json_decode($audit->before, true)['attendance'][0]['id'])->toBe($attendance);
    $this->assertDatabaseCount('events', 1);
    $this->assertDatabaseCount('official_assignments', 0);
    $this->assertDatabaseCount('event_changes', 3);
});

it('counts scheduled Rangers across all areas only when enabled and once per interval', function () {
    foreach ([$this->rangers[0], $this->rangers[1], $this->rangers[1]] as $i => $member) {
        OfficialAssignment::create(['organization_id' => $this->manager->organization_id, 'organization_membership_id' => $member->id,
            'starts_at' => '2026-10-13T01:00:00Z', 'ends_at' => $i === 0 ? '2026-10-13T02:00:00Z' : '2026-10-13T01:30:00Z',
            'revision' => 1, 'shift_context' => ['area_names' => [$i === 0 ? 'North' : 'South']]]);
    }
    $this->actingAs($this->manager->user)->putJson($this->url, [...$this->input, 'counts_scheduled' => true])->assertCreated()
        ->assertJsonPath('evaluation.segments.0.counted', 2)->assertJsonPath('evaluation.segments.0.shortfall', 0)
        ->assertJsonPath('evaluation.segments.1.counted', 1)->assertJsonPath('evaluation.maximum_shortfall', 1)
        ->assertJsonCount(1, 'attendance')->assertJsonCount(1, 'evaluation.paid_attendance');
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1])->assertOk()->assertJsonPath('evaluation.minimum_counted', 1);
    expect(OfficialAssignment::pluck('revision')->all())->toBe([1, 1, 1]);
    $this->assertDatabaseCount('official_assignment_changes', 0);
    $this->assertDatabaseCount('phase_coverages', 0);
});

it('flags unavailable and inactive attendance without treating it as reliable staffing', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, [...$this->input, 'attendance_membership_ids' => $this->rangers->pluck('id')->all()])->assertCreated();
    Unavailability::create(['organization_id' => $this->manager->organization_id, 'organization_membership_id' => $this->rangers[0]->id,
        'starts_at' => '2026-10-13T01:15:00Z', 'ends_at' => '2026-10-13T01:45:00Z', 'revision' => 1]);
    $this->rangers[1]->update(['is_active' => false]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('evaluation.active_rangers', 2)->assertJsonPath('evaluation.minimum_counted', 1)
        ->assertJsonPath('evaluation.unavailable_membership_ids.0', $this->rangers[0]->id)
        ->assertJsonPath('evaluation.inactive_membership_ids.0', $this->rangers[1]->id)->assertJsonCount(3, 'attendance');
    $this->get(str_replace('/api/v1', '/admin', $this->url))->assertOk()->assertSee('(inactive; remove to save)');
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'attendance_membership_ids' => $this->rangers->pluck('id')->all()])->assertUnprocessable();
    $this->getJson($this->url)->assertJsonCount(3, 'attendance');
});

it('validates event configuration before any write', function (string $field, mixed $value) {
    $input = $this->input;
    data_set($input, $field, $value);
    $this->actingAs($this->manager->user)->putJson($this->url, $input)->assertUnprocessable();
    $this->assertDatabaseCount('events', 0);
    $this->assertDatabaseCount('event_attendances', 0);
    $this->assertDatabaseCount('event_changes', 0);
})->with([
    ['name', ''], ['expected_revision', 0], ['expected_revision', 1.2], ['expected_season_revision', null],
    ['starts_local', '2026-02-30 18:00:00'], ['ends_local', '2026-10-12 18:00:00'], ['ends_local', '2027-01-01 01:00:00'],
    ['starts_offset', '+00:00'], ['target_type', 'recurring'], ['target_value', 101], ['target_value', -1],
    ['target_value', 25.5], ['target_value', null], ['paid', 'yes'], ['counts_scheduled', null],
    ['attendance_membership_ids', null], ['attendance_membership_ids.0', 'invalid'], ['recurrence', 'weekly'],
]);

it('supports optional and numeric targets including zero without generating recurrence', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, [...$this->input, 'target_type' => 'none', 'target_value' => null])->assertCreated()->assertJsonPath('evaluation.required', null);
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'target_type' => 'number', 'target_value' => 4])->assertOk()->assertJsonPath('evaluation.maximum_shortfall', 3);
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 2, 'target_type' => 'number', 'target_value' => 0])->assertOk()->assertJsonPath('evaluation.required', 0);
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 3, 'target_type' => 'none', 'target_value' => 1])->assertUnprocessable();
    $this->assertDatabaseCount('events', 1);
});

it('requires current same-organization Ranger attendance and rejects duplicates', function () {
    $foreign = OrganizationMembership::factory()->create();
    $inactive = OrganizationMembership::factory()->create(['organization_id' => $this->manager->organization_id, 'is_active' => false]);
    foreach ([[$foreign->id], [$inactive->id], [$this->manager->id], [$this->rangers[0]->id, $this->rangers[0]->id]] as $members) {
        $this->actingAs($this->manager->user)->putJson($this->url, [...$this->input, 'attendance_membership_ids' => $members])->assertUnprocessable();
    }
});

it('protects service API and Orchid by organization and active management role', function () {
    $admin = str_replace('/api/v1', '/admin', $this->base).'/new';
    $this->getJson($this->base)->assertUnauthorized();
    $this->putJson($this->url, $this->input)->assertUnauthorized();
    foreach ([$this->rangers[0], OrganizationMembership::factory()->create(['role' => 'management'])] as $denied) {
        Auth::forgetGuards();
        $this->actingAs($denied->user)->getJson($this->base)->assertForbidden();
        $this->putJson($this->url, $this->input)->assertForbidden();
        $this->get($admin)->assertForbidden();
        $this->post($admin.'/save', ['configuration' => $this->input])->assertForbidden();
    }
    Auth::forgetGuards();
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertCreated();
    $foreign = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->actingAs($foreign->user)->getJson(str_replace($this->manager->organization_id, $foreign->organization_id, $this->url))->assertNotFound();
    $this->manager->update(['is_active' => false]);
    expect(fn () => app(SaveEvent::class)->handle($this->manager->user, $this->manager->organization, $this->season->id, $this->id, $this->input))->toThrow(AuthorizationException::class);
});

it('rejects stale event and season revisions and preserves audited attendance on rollback', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertCreated();
    $this->putJson($this->url, $this->input)->assertConflict();
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'expected_season_revision' => 2])->assertConflict();
    DB::table('event_changes')->insert(['id' => (string) Str::uuid(), 'event_id' => $this->id, 'actor_id' => $this->manager->user_id,
        'revision' => 2, 'reason' => 'Force audit collision', 'after' => '{}', 'occurred_at' => now()]);
    expect(fn () => app(SaveEvent::class)->handle($this->manager->user, $this->manager->organization, $this->season->id, $this->id,
        [...$this->input, 'expected_revision' => 1, 'attendance_membership_ids' => []]))->toThrow(QueryException::class);
    $this->getJson($this->url)->assertJsonPath('revision', 1)->assertJsonCount(1, 'attendance');
    $this->season->update(['revision' => 2]);
    $this->getJson($this->url)->assertJsonPath('requires_review', true)->assertJsonPath('starts_at', '2026-10-13T01:00:00Z');
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1])->assertConflict();
});

it('uses elapsed time over DST and splits paid source intervals across local weeks', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, [...$this->input, 'starts_local' => '2026-03-08 02:30:00', 'ends_local' => '2026-03-08 04:00:00'])->assertUnprocessable();
    $fold = [...$this->input, 'starts_local' => '2026-11-01 01:30:00', 'ends_local' => '2026-11-01 02:30:00'];
    $this->putJson($this->url, $fold)->assertUnprocessable();
    $this->putJson($this->url, [...$fold, 'starts_offset' => '-07:00'])->assertCreated()->assertJsonPath('evaluation.paid_attendance.0.allocations.0.elapsed_seconds', 7200);
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'starts_local' => '2026-10-11 23:30:00', 'ends_local' => '2026-10-12 00:30:00'])->assertOk()
        ->assertJsonPath('evaluation.paid_attendance.0.allocations.0.week_starts_on', '2026-10-05')
        ->assertJsonPath('evaluation.paid_attendance.0.allocations.1.week_starts_on', '2026-10-12')
        ->assertJsonPath('evaluation.paid_attendance.0.allocations.1.elapsed_seconds', 1800);
});

it('shares validation and stale input retention with the Orchid form', function () {
    $admin = str_replace('/api/v1', '/admin', $this->base);
    $this->actingAs($this->manager->user, 'web')->get($admin.'/new')->assertOk()->assertSee('Save event and attendance');
    $form = [...$this->input, 'id' => $this->id, 'starts_local' => '2026-10-12T18:00', 'ends_local' => '2026-10-12T19:00'];
    $this->post($admin.'/new/save', ['configuration' => $form])->assertRedirect($admin.'/'.$this->id);
    $this->get($admin.'/'.$this->id)->assertOk()->assertSee('Maximum shortfall: 1');
    $this->from($admin.'/'.$this->id)->post($admin.'/'.$this->id.'/save', ['configuration' => $form])->assertSessionHasErrors('configuration')->assertSessionHasInput('configuration.name', 'Monday Meeting');
    $this->post($admin.'/'.$this->id.'/save', ['configuration' => [...$form, 'expected_revision' => 1, 'target_value' => 101]])->assertSessionHasErrors('configuration.target_value');
});

it('excludes unavailable scheduled Rangers and ignores merely touching shifts', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, [...$this->input, 'counts_scheduled' => true, 'attendance_membership_ids' => []])->assertCreated();
    foreach ([['2026-10-13T00:00:00Z', '2026-10-13T01:00:00Z'], ['2026-10-13T02:00:00Z', '2026-10-13T03:00:00Z']] as [$start, $end]) {
        OfficialAssignment::create(['organization_id' => $this->manager->organization_id, 'organization_membership_id' => $this->rangers[0]->id,
            'starts_at' => $start, 'ends_at' => $end, 'revision' => 1]);
    }
    $this->getJson($this->url)->assertJsonPath('evaluation.minimum_counted', 0)->assertJsonCount(1, 'evaluation.segments');
    OfficialAssignment::create(['organization_id' => $this->manager->organization_id, 'organization_membership_id' => $this->rangers[1]->id,
        'starts_at' => '2026-10-13T01:00:00Z', 'ends_at' => '2026-10-13T02:00:00Z', 'revision' => 1]);
    $this->getJson($this->url)->assertJsonPath('evaluation.minimum_counted', 1)->assertJsonCount(0, 'evaluation.paid_attendance');
    Unavailability::create(['organization_id' => $this->manager->organization_id, 'organization_membership_id' => $this->rangers[1]->id,
        'starts_at' => '2026-10-13T01:00:00Z', 'ends_at' => '2026-10-13T02:00:00Z', 'revision' => 1]);
    $this->getJson($this->url)->assertJsonPath('evaluation.minimum_counted', 0);
});

it('prevents event identity reuse in another season and supports a zero-person roster', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertCreated();
    $other = $this->season->replicate();
    $other->save();
    $url = str_replace($this->season->id, $other->id, $this->url);
    $this->getJson($url)->assertNotFound();
    $this->putJson($url, [...$this->input, 'expected_revision' => 1])->assertNotFound();
    OrganizationMembership::whereIn('id', $this->rangers->pluck('id'))->update(['is_active' => false]);
    $this->getJson($this->url)->assertJsonPath('evaluation.required', 0)->assertJsonPath('evaluation.minimum_counted', 0);
});

it('preserves existing seasons through event migration rollback and reapplication', function () {
    // Roll back dependent migrations first, as Artisan does in production.
    $planning = require database_path('migrations/2026_10_14_000001_create_planning_inputs_table.php');
    $planning->down();
    $migration = require database_path('migrations/2026_10_13_220000_create_events.php');
    $migration->down();
    expect(Schema::hasTable('events'))->toBeFalse();
    $this->assertDatabaseHas('seasons', ['id' => $this->season->id, 'revision' => 1]);
    $migration->up();
    $planning->up();
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertCreated();
});

it('enforces tenant foreign keys and preserves referenced event history', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertCreated();
    $foreign = OrganizationMembership::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('events')->where('id', $this->id)->update(['organization_id' => $foreign->organization_id])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('event_attendances')->where('event_id', $this->id)->update(['organization_membership_id' => $foreign->id])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('events')->where('id', $this->id)->delete()))->toThrow(QueryException::class);
});

it('enforces event interval revision and target checks in PostgreSQL', function (array $fields) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL check constraints.');
    }
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertCreated();
    expect(fn () => DB::transaction(fn () => DB::table('events')->where('id', $this->id)->update($fields)))->toThrow(QueryException::class);
})->with([
    [['revision' => 0]], [['target_value' => 101]], [['target_type' => 'none', 'target_value' => 10]],
    [['ends_at' => '2026-10-13T01:00:00Z']], [['target_type' => 'percentage', 'target_value' => null]],
]);
