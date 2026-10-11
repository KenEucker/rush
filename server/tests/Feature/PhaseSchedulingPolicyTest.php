<?php

use App\Models\OrganizationMembership;
use App\Services\Seasons\PhaseSchedulingPolicy;
use App\Services\Seasons\SavePhaseCoverage;
use App\Services\Seasons\SavePhaseSchedulingPolicy;
use App\Services\Seasons\SaveSeason;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->seasonInput = ['name' => 'Season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        'timezone' => 'America/Los_Angeles', 'week_starts_on' => 1, 'expected_revision' => null,
        'phases' => [['name' => 'Low', 'starts_on' => '2026-01-01', 'ends_on' => '2026-05-31'],
            ['name' => 'Full', 'starts_on' => '2026-06-01', 'ends_on' => '2026-12-31']]];
    $this->season = app(SaveSeason::class)->handle($this->manager->user, $this->manager->organization, (string) Str::uuid(), $this->seasonInput);
    $this->phase = $this->season->phases->first();
    $this->coverageInput = ['expected_revision' => null, 'expected_season_revision' => 1, 'model' => 'dedicated', 'reason' => 'Configure',
        'shifts' => [['name' => 'Night', 'start_time' => '22:00', 'duration_minutes' => 480]],
        'areas' => [['name' => 'North']], 'groups' => [], 'staffing' => []];
    $this->coverage = app(SavePhaseCoverage::class)->handle($this->manager->user, $this->manager->organization, $this->phase->id, $this->coverageInput);
    $this->shift = $this->coverage['shifts'][0]['id'];
    $this->url = '/api/v1/organizations/'.$this->manager->organization_id.'/phases/'.$this->phase->id.'/policies';
    $this->input = ['expected_revision' => null, 'expected_season_revision' => 1, 'expected_coverage_revision' => 1,
        'weekly_target_minutes' => 1920, 'planned_cap_minutes' => 2400, 'planned_warning_minutes' => 2100, 'actual_warning_minutes' => 2400,
        'baselines' => [['shift_id' => $this->shift, 'classification' => 'neutral']],
        'weekday_overrides' => [['shift_id' => $this->shift, 'weekday' => 1, 'classification' => 'undesirable']],
        'date_overrides' => [['shift_id' => $this->shift, 'date' => '2026-01-05', 'classification' => 'desirable']], 'reason' => 'Initial policy'];
});

it('persists exact phase inputs and audited replacements without changing coverage or hours', function () {
    $this->actingAs($this->manager->user)->getJson($this->url)->assertOk()->assertJsonPath('revision', null)->assertJsonPath('weekly_target_minutes', null)->assertJsonPath('baselines.0.classification', null);
    $saved = $this->putJson($this->url, $this->input)->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('requires_review', false)->assertJsonPath('weekly_target_minutes', 1920)->json();
    $resolver = app(PhaseSchedulingPolicy::class);
    expect($resolver->classification($saved, $this->shift, '2026-01-05'))->toBe(['classification' => 'desirable', 'source' => 'date'])
        ->and($resolver->classification($saved, $this->shift, '2026-01-12'))->toBe(['classification' => 'undesirable', 'source' => 'weekday'])
        ->and($resolver->classification($saved, $this->shift, '2026-01-06'))->toBe(['classification' => 'neutral', 'source' => 'baseline']);
    $replacement = [...$this->input, 'expected_revision' => 1, 'date_overrides' => [], 'reason' => 'Remove exception'];
    $updated = $this->putJson($this->url, $replacement)->assertOk()->assertJsonPath('revision', 2)->json();
    expect($resolver->classification($updated, $this->shift, '2026-01-05')['classification'])->toBe('undesirable');
    $this->putJson($this->url, $replacement)->assertConflict();
    $audit = DB::table('phase_policy_changes')->where('revision', 2)->first();
    expect($audit->actor_id)->toBe($this->manager->user_id)->and($audit->reason)->toBe('Remove exception')
        ->and(json_decode($audit->before, true)['date_overrides'])->toHaveCount(1)
        ->and(json_decode($audit->after, true)['date_overrides'])->toBe([]);
    $this->assertDatabaseCount('phase_policy_changes', 2);
    $this->assertDatabaseCount('official_assignments', 0);
    $this->assertDatabaseHas('phase_coverages', ['phase_id' => $this->phase->id, 'revision' => 1]);
});

it('rejects invalid policies atomically', function (string $field, mixed $value) {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertOk();
    $input = [...$this->input, 'expected_revision' => 1];
    data_set($input, $field, $value);
    $this->putJson($this->url, $input)->assertUnprocessable();
    $this->getJson($this->url)->assertJsonPath('revision', 1)->assertJsonPath('weekly_target_minutes', 1920);
    $this->assertDatabaseCount('phase_policy_changes', 1);
    $this->assertDatabaseCount('shift_desirabilities', 3);
})->with([
    ['weekly_target_minutes', -1], ['weekly_target_minutes', 12.5], ['planned_cap_minutes', 1919],
    ['actual_warning_minutes', null], ['planned_warning_minutes', -1], ['planned_cap_minutes', 2147483648],
    ['baselines', []], ['baselines.0.classification', 'unwanted'], ['baselines.0.shift_id', 'invalid'],
    ['weekday_overrides.0.weekday', 0], ['weekday_overrides.0.weekday', 8], ['weekday_overrides.0.weekday', 1.5],
    ['date_overrides.0.date', '2026-02-30'], ['date_overrides.0.date', '2026-06-01'], ['date_overrides.0.date', '2025-12-31'],
    ['reason', ''], ['date_overrides.0.classification', 'preferred'],
]);

it('rejects duplicate overrides and foreign phase shifts', function () {
    $this->actingAs($this->manager->user);
    foreach (['baselines', 'weekday_overrides', 'date_overrides'] as $field) {
        $input = $this->input;
        $input[$field][] = $input[$field][0];
        $this->putJson($this->url, $input)->assertUnprocessable();
        $input = $this->input;
        $input[$field][0]['shift_id'] = (string) Str::uuid();
        $this->putJson($this->url, $input)->assertUnprocessable();
    }
    $this->assertDatabaseCount('phase_scheduling_policies', 0);
});

it('allows explicit zero and independent warning thresholds without legal defaults', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, [...$this->input, 'weekly_target_minutes' => 0,
        'planned_cap_minutes' => 0, 'planned_warning_minutes' => 5000, 'actual_warning_minutes' => 0])->assertOk()->assertJsonPath('planned_cap_minutes', 0);
});

it('keeps phase policies independent and does not infer missing baselines', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertOk();
    $other = $this->season->phases->last();
    $coverage = app(SavePhaseCoverage::class)->handle($this->manager->user, $this->manager->organization, $other->id, $this->coverageInput);
    $url = str_replace($this->phase->id, $other->id, $this->url);
    $this->getJson($url)->assertJsonPath('weekly_target_minutes', null)->assertJsonPath('baselines.0.classification', null);
    $this->putJson($url, [...$this->input, 'weekly_target_minutes' => 1440, 'baselines' => [['shift_id' => $coverage['shifts'][0]['id'], 'classification' => 'desirable']],
        'weekday_overrides' => [], 'date_overrides' => []])->assertOk();
    $this->getJson($this->url)->assertJsonPath('weekly_target_minutes', 1920)->assertJsonPath('baselines.0.classification', 'neutral');
});

it('checks current management access and organization isolation on API Orchid and service', function () {
    $admin = str_replace('/api/v1', '/admin', $this->url);
    $this->getJson($this->url)->assertUnauthorized();
    $this->putJson($this->url, $this->input)->assertUnauthorized();
    $ranger = OrganizationMembership::factory()->create(['organization_id' => $this->manager->organization_id]);
    $this->actingAs($ranger->user)->getJson($this->url)->assertForbidden();
    $this->putJson($this->url, $this->input)->assertForbidden();
    $this->get($admin)->assertForbidden();
    $this->post($admin.'/save', ['configuration' => $this->input])->assertForbidden();
    $foreign = OrganizationMembership::factory()->create(['role' => 'management']);
    Auth::forgetGuards();
    $this->actingAs($foreign->user)->getJson($this->url)->assertForbidden();
    $foreignUrl = str_replace($this->manager->organization_id, $foreign->organization_id, $this->url);
    $this->getJson($foreignUrl)->assertNotFound();
    $this->putJson($foreignUrl, $this->input)->assertNotFound();
    Auth::forgetGuards();
    $this->actingAs($this->manager->user, 'web')->get($admin)->assertOk()->assertSee('Save scheduling policies');
    $this->post($admin.'/save', ['configuration' => $this->input])->assertRedirect($admin);
    $this->manager->update(['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
    $this->putJson($this->url, $this->input)->assertForbidden();
    expect(fn () => app(SavePhaseSchedulingPolicy::class)->handle($this->manager->user, $this->manager->organization, $this->phase->id, $this->input))->toThrow(AuthorizationException::class);
});

it('requires review after coverage changes and preserves audit when a shift is removed', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertOk();
    app(SavePhaseCoverage::class)->handle($this->manager->user, $this->manager->organization, $this->phase->id, [...$this->coverageInput, 'expected_revision' => 1]);
    $saved = $this->getJson($this->url)->assertJsonPath('requires_review', true)->assertJsonPath('baselines.0.classification', null)->json();
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1])->assertConflict();
    expect(fn () => app(PhaseSchedulingPolicy::class)->classification($saved, $this->shift, '2026-01-05'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('shift_desirabilities', 0);
    expect(json_decode(DB::table('phase_policy_changes')->first()->after, true)['baselines'][0]['shift_id'])->toBe($this->shift);
});

it('requires review after calendar edits and revalidates dated overrides against current bounds', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertOk();
    $phases = $this->season->phases->map->only(['id', 'name', 'starts_on', 'ends_on'])->all();
    $phases[0]['starts_on'] = '2026-01-06';
    app(SaveSeason::class)->handle($this->manager->user, $this->manager->organization, $this->season->id, [...$this->seasonInput, 'expected_revision' => 1, 'reason' => 'Later start', 'phases' => $phases]);
    $this->getJson($this->url)->assertJsonPath('requires_review', true);
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'expected_season_revision' => 2])->assertUnprocessable();
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'expected_season_revision' => 2, 'date_overrides' => []])->assertOk()->assertJsonPath('requires_review', false);
});

it('rolls back the entire save if its audit cannot be written', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertOk();
    DB::table('phase_policy_changes')->insert(['id' => (string) Str::uuid(), 'phase_id' => $this->phase->id, 'actor_id' => $this->manager->user_id,
        'revision' => 2, 'reason' => 'Force collision', 'after' => '{}', 'occurred_at' => now()]);
    expect(fn () => app(SavePhaseSchedulingPolicy::class)->handle($this->manager->user, $this->manager->organization, $this->phase->id,
        [...$this->input, 'expected_revision' => 1, 'weekly_target_minutes' => 0, 'date_overrides' => []]))->toThrow(QueryException::class);
    $this->getJson($this->url)->assertJsonPath('revision', 1)->assertJsonPath('weekly_target_minutes', 1920)->assertJsonCount(1, 'date_overrides');
});

it('uses the supplied local start date on DST and overnight shifts and rejects out of phase dates', function () {
    $this->actingAs($this->manager->user);
    $saved = $this->putJson($this->url, [...$this->input, 'date_overrides' => [['shift_id' => $this->shift, 'date' => '2026-03-08', 'classification' => 'undesirable']]])->assertOk()->json();
    $resolver = app(PhaseSchedulingPolicy::class);
    expect($resolver->classification($saved, $this->shift, '2026-03-08')['source'])->toBe('date')
        ->and($resolver->classification($saved, $this->shift, '2026-03-09')['source'])->toBe('weekday');
    expect(fn () => $resolver->classification($saved, $this->shift, '2026-06-01'))->toThrow(ValidationException::class);
    expect(fn () => $resolver->classification($saved, (string) Str::uuid(), '2026-03-09'))->toThrow(ValidationException::class);
});

it('rejects stale calendar and coverage revisions even when submitted values are valid', function () {
    $this->actingAs($this->manager->user);
    foreach (['expected_season_revision', 'expected_coverage_revision'] as $field) {
        $this->putJson($this->url, [...$this->input, $field => 2])->assertConflict();
    }
    $this->assertDatabaseCount('phase_scheduling_policies', 0);
    $this->assertDatabaseCount('phase_policy_changes', 0);
});

it('preserves baseline identity through shift renaming and explicitly reviews new coverage revisions', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertOk();
    $coverage = [...$this->coverage, 'expected_revision' => 1, 'expected_season_revision' => 1, 'reason' => 'Rename night'];
    $coverage['shifts'][0]['name'] = 'Late patrol';
    app(SavePhaseCoverage::class)->handle($this->manager->user, $this->manager->organization, $this->phase->id, $coverage);
    $this->getJson($this->url)->assertJsonPath('requires_review', true)->assertJsonPath('baselines.0.shift_name', 'Late patrol')->assertJsonPath('baselines.0.classification', 'neutral');
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'expected_coverage_revision' => 2])->assertOk()->assertJsonPath('requires_review', false);
});

it('enforces production database constraints independently of request validation', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Production PostgreSQL check constraints.');
    }
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertOk();
    foreach ([['weekly_target_minutes' => -1], ['planned_cap_minutes' => 1], ['actual_warning_minutes' => -1], ['revision' => 0]] as $invalid) {
        expect(fn () => DB::transaction(fn () => DB::table('phase_scheduling_policies')->where('phase_id', $this->phase->id)->update($invalid)))->toThrow(QueryException::class);
    }
    foreach ([['classification' => 'preferred'], ['scope_key' => '8', 'scope' => 'weekday'], ['scope' => 'unknown']] as $invalid) {
        expect(fn () => DB::transaction(fn () => DB::table('shift_desirabilities')->where('phase_id', $this->phase->id)->update($invalid)))->toThrow(QueryException::class);
    }
    $row = (array) DB::table('shift_desirabilities')->first();
    expect(fn () => DB::transaction(fn () => DB::table('shift_desirabilities')->insert([...$row, 'id' => (string) Str::uuid()])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('shift_desirabilities')->insert([...$row, 'id' => (string) Str::uuid(), 'shift_id' => (string) Str::uuid()])))->toThrow(QueryException::class);
});
