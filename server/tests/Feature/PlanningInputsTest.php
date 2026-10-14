<?php

use App\Models\OrganizationMembership;
use App\Services\Availability\SaveUnavailability;
use App\Services\Events\SaveEvent;
use App\Services\Preferences\SavePreferenceProfile;
use App\Services\Scheduling\PlanningConstraints;
use App\Services\Scheduling\PlanningHours;
use App\Services\Scheduling\PlanningInputs;
use App\Services\Scheduling\SaveOfficialAssignment;
use App\Services\Seasons\SavePhaseCoverage;
use App\Services\Seasons\SavePhaseSchedulingPolicy;
use App\Services\Seasons\SaveSeason;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->ranger = OrganizationMembership::factory()->create(['role' => 'ranger', 'organization_id' => $this->manager->organization_id]);
    $this->season = app(SaveSeason::class)->handle($this->manager->user, $this->manager->organization, (string) Str::uuid(), [
        'name' => 'Planning season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'timezone' => 'America/Los_Angeles',
        'week_starts_on' => 1, 'expected_revision' => null,
        'phases' => [['name' => 'Early', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-03'],
            ['name' => 'Late', 'starts_on' => '2026-06-04', 'ends_on' => '2026-12-31']]]);
    foreach ($this->season->phases as $index => $phase) {
        $coverage = app(SavePhaseCoverage::class)->handle($this->manager->user, $this->manager->organization, $phase->id, [
            'expected_revision' => null, 'expected_season_revision' => 1, 'reason' => 'Setup',
            'shifts' => [['name' => 'Day', 'start_time' => '08:00', 'duration_minutes' => 480]],
            'areas' => [['name' => 'North']], 'groups' => [], 'staffing' => []]);
        $shift = $coverage['shifts'][0]['id'];
        DB::table('coverage_staffing')->insert(['id' => (string) Str::uuid(), 'phase_id' => $phase->id, 'model' => 'dedicated',
            'shift_id' => $shift, 'area_id' => $coverage['areas'][0]['id'], 'group_id' => null, 'positions' => 1]);
        app(SavePhaseSchedulingPolicy::class)->handle($this->manager->user, $this->manager->organization, $phase->id, [
            'expected_revision' => null, 'expected_season_revision' => 1, 'expected_coverage_revision' => 1,
            'weekly_target_minutes' => $index ? 2100 : 1400, 'planned_cap_minutes' => $index ? 2800 : 2100,
            'planned_warning_minutes' => $index ? 2100 : 1400, 'actual_warning_minutes' => 2800,
            'baselines' => [['shift_id' => $shift, 'classification' => 'neutral']], 'weekday_overrides' => [], 'date_overrides' => [], 'reason' => 'Setup']);
    }
    $this->url = '/api/v1/organizations/'.$this->manager->organization_id.'/seasons/'.$this->season->id.'/planning-inputs';
    $this->input = ['starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'reason' => 'Review inputs'];
    $this->capture = fn (array $extra = []) => app(PlanningInputs::class)->capture($this->manager->user, $this->manager->organization, $this->season->id, [...$this->input, ...$extra]);
    $this->commitment = fn (string $start, string $end) => app(SaveOfficialAssignment::class)->handle($this->manager->user,
        $this->manager->organization, (string) Str::uuid(), ['membership_id' => $this->ranger->id, 'expected_revision' => null,
            'starts_at' => $start, 'ends_at' => $end, 'reason' => 'Published work']);
    $this->candidate = fn ($record, array $extra = []) => ['membership_id' => $this->ranger->id,
        'opportunity_id' => $record['inputs']['opportunities'][0]['id'], ...$extra];
});

it('captures arbitrary single-day ranges with full-week prorated policies and deterministic immutable revisions', function () {
    $a = ($this->capture)();
    $b = ($this->capture)();
    expect($a['fingerprint'])->toBe($b['fingerprint'])->and($a['id'])->not->toBe($b['id']);
    $week = $a['inputs']['weeks']['2026-06-01'];
    expect($week['policy_minute_sevenths']['planned_cap_minutes'])->toBe(3 * 2100 + 4 * 2800)
        ->and($week['policy_minute_sevenths']['weekly_target_minutes'])->toBe(3 * 1400 + 4 * 2100)
        ->and($week['phase_days'])->toHaveCount(7)
        ->and($a['inputs']['parameters']['minimum_rest_minutes'])->toBe(480);
    $row = DB::table('planning_inputs')->find($a['id']);
    expect($row->actor_id)->toBe($this->manager->user_id)->and($row->reason)->toBe('Review inputs');
    $this->assertDatabaseCount('official_assignments', 0);
    expect(app(PlanningInputs::class)->read($this->manager->user, $this->manager->organization, $a['id'])['stale'])->toBeFalse();
});

it('authorizes API capture read evaluation and Orchid by current organization membership', function () {
    $record = $this->actingAs($this->manager->user)->postJson($this->url, $this->input)->assertCreated()->json();
    $url = '/api/v1/organizations/'.$this->manager->organization_id.'/planning-inputs/'.$record['id'];
    $this->getJson($url)->assertOk()->assertJsonPath('stale', false);
    $this->postJson($url.'/evaluate', ['candidates' => [($this->candidate)($record)]])->assertOk()->assertJsonPath('hypothetical', true)->assertJsonPath('candidates.0.automatic_eligible', true);
    $screen = route('platform.planning-inputs', [$this->manager->organization_id, $this->season->id, 'snapshot' => $record['id']]);
    Auth::forgetGuards();
    $this->actingAs($this->manager->user, 'web')->get($screen)->assertOk()->assertSee('Captured input review')->assertSee('8 hours');
    $this->actingAs($this->ranger->user)->postJson($this->url, $this->input)->assertForbidden();
    $this->getJson($url)->assertForbidden();
    $this->postJson($url.'/evaluate', ['candidates' => []])->assertForbidden();
    $this->get($screen)->assertForbidden();
    $outsider = OrganizationMembership::factory()->create(['role' => 'management']);
    Auth::forgetGuards();
    $this->actingAs($outsider->user)->getJson($url)->assertForbidden();
    $this->postJson(str_replace($this->manager->organization_id, $outsider->organization_id, $this->url), $this->input)->assertNotFound();
    $this->manager->update(['is_active' => false]);
    Auth::forgetGuards();
    $this->actingAs($this->manager->user)->getJson($url)->assertForbidden();
});

it('validates date ranges rest and reasons without persisting failed captures', function ($extra) {
    $this->actingAs($this->manager->user)->postJson($this->url, [...$this->input, ...$extra])->assertUnprocessable();
    $this->assertDatabaseCount('planning_inputs', 0);
})->with([
    [['starts_on' => '2026-02-30']], [['ends_on' => '2026-06-02']], [['starts_on' => '2025-12-31']],
    [['ends_on' => '2027-01-01']], [['minimum_rest_minutes' => -1]], [['minimum_rest_minutes' => 1.5]], [['reason' => '']],
]);

it('detects changed inserted and removed sources without rewriting original snapshots', function () {
    $record = ($this->capture)();
    $original = DB::table('planning_inputs')->find($record['id'])->inputs;
    ($this->commitment)('2026-06-02T15:00:00Z', '2026-06-02T23:00:00Z');
    expect(app(PlanningInputs::class)->read($this->manager->user, $this->manager->organization, $record['id'])['stale'])->toBeTrue();
    $second = ($this->capture)();
    $this->ranger->update(['is_active' => false]);
    expect(app(PlanningInputs::class)->read($this->manager->user, $this->manager->organization, $second['id'])['stale'])->toBeTrue();
    expect(DB::table('planning_inputs')->find($record['id'])->inputs)->toBe($original);
});

it('distinguishes hard restrictions explicit overrides and positive preferences', function () {
    app(SaveUnavailability::class)->handle($this->ranger->user, $this->ranger, (string) Str::uuid(), null,
        ['starts_at' => '2026-06-03T15:00:00Z', 'ends_at' => '2026-06-03T16:00:00Z']);
    $record = ($this->capture)();
    $evaluate = fn ($candidate) => app(PlanningConstraints::class)->evaluate($record['inputs'], ['candidates' => [$candidate]])['candidates'][0];
    $blocked = $evaluate(($this->candidate)($record));
    expect($blocked['automatic_eligible'])->toBeFalse()->and($blocked['explicit_override_eligible'])->toBeFalse()
        ->and($blocked['overridable'][0]['code'])->toBe('unavailable')
        ->and($blocked['preferences']['2026-06-01']['positive_shift_match'])->toBeFalse();
    expect($evaluate(($this->candidate)($record, ['override_reason' => 'Manager emergency review']))['explicit_override_eligible'])->toBeTrue();
    $hard = $evaluate(($this->candidate)($record, ['membership_id' => (string) Str::uuid(), 'override_reason' => 'Cannot bypass eligibility']));
    expect($hard['hard'])->toContain('ineligible_member')->and($hard['explicit_override_eligible'])->toBeFalse();
});

it('enforces rest at exact elapsed boundaries using commitments outside the selected dates', function (string $end, bool $eligible) {
    ($this->commitment)('2026-06-02T23:00:00Z', $end);
    $record = ($this->capture)();
    $result = app(PlanningConstraints::class)->evaluate($record['inputs'], ['candidates' => [($this->candidate)($record)]]);
    expect($result['candidates'][0]['automatic_eligible'])->toBe($eligible);
})->with([['2026-06-03T07:00:00Z', true], ['2026-06-03T07:00:01Z', false]]);

it('checks proposed work against other candidates and preserves official records', function () {
    $record = ($this->capture)();
    $result = app(PlanningConstraints::class)->evaluate($record['inputs'], ['candidates' => [($this->candidate)($record), ($this->candidate)($record)]]);
    expect($result['candidates'][1]['hard'])->toContain('duplicate_candidate');
    $assignment = ($this->commitment)('2026-06-03T15:00:00Z', '2026-06-03T23:00:00Z');
    $record = ($this->capture)();
    $result = app(PlanningConstraints::class)->evaluate($record['inputs'], ['candidates' => [($this->candidate)($record)]]);
    expect(array_column($result['candidates'][0]['overridable'], 'code'))->toContain('overlapping_work');
    expect($assignment->fresh()->revision)->toBe(1);
    $this->assertDatabaseCount('official_assignments', 1);
});

it('counts outside-range full-week commitments toward caps and separate warnings', function () {
    ($this->commitment)('2026-06-01T07:00:00Z', '2026-06-02T23:00:00Z');
    $record = ($this->capture)();
    $result = app(PlanningConstraints::class)->evaluate($record['inputs'], ['candidates' => [($this->candidate)($record)]])['candidates'][0];
    expect(array_column($result['overridable'], 'code'))->toContain('weekly_cap')
        ->and($result['warnings'][0]['code'])->toBe('planned_hours_warning');
});

it('unions paid intervals exactly and clips at DST and week boundaries', function () {
    $ranges = [
        ['starts_at' => '2026-03-08T08:00:00Z', 'ends_at' => '2026-03-09T07:00:00Z', 'paid' => true],
        ['starts_at' => '2026-03-08T09:00:00Z', 'ends_at' => '2026-03-08T12:00:00Z', 'paid' => true],
        ['starts_at' => '2026-03-09T07:00:00Z', 'ends_at' => '2026-03-09T09:00:00Z', 'paid' => false],
    ];
    expect(app(PlanningHours::class)->seconds($ranges, '2026-03-08T08:00:00Z', '2026-03-10T07:00:00Z'))->toBe(23 * 3600)
        ->and(app(PlanningHours::class)->seconds($ranges, '2026-03-08T10:00:00Z', '2026-03-08T11:00:00Z'))->toBe(3600);
});

it('snapshots paid events independently of area opportunities with roster-based percentage targets', function () {
    app(SaveEvent::class)->handle($this->manager->user, $this->manager->organization, $this->season->id, (string) Str::uuid(), [
        'name' => 'Meeting', 'expected_revision' => null, 'expected_season_revision' => 1,
        'starts_local' => '2026-06-03 10:00:00', 'ends_local' => '2026-06-03 11:00:00', 'paid' => true,
        'counts_scheduled' => true, 'target_type' => 'percentage', 'target_value' => 50,
        'attendance_membership_ids' => [$this->ranger->id], 'reason' => 'Meeting']);
    ($this->commitment)('2026-06-03T15:00:00Z', '2026-06-03T23:00:00Z');
    $record = ($this->capture)();
    expect($record['inputs']['opportunities'])->toHaveCount(2)
        ->and($record['inputs']['opportunities'][1]['positions'])->toBe(1)
        ->and(app(PlanningHours::class)->seconds($record['inputs']['commitments'], '2026-06-01T07:00:00Z', '2026-06-08T07:00:00Z'))->toBe(8 * 3600);
});

it('retains DST problems as actionable input issues and resolves repeated times only with an explicit offset', function () {
    DB::table('shift_definitions')->update(['start_time' => '01:30']);
    $record = ($this->capture)(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-01']);
    expect($record['inputs']['issues'][0]['code'])->toBe('invalid_shift_time');
    $key = $record['inputs']['issues'][0]['key'];
    $resolved = ($this->capture)(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'offsets' => [$key => '-08:00']]);
    expect($resolved['inputs']['issues'])->toBe([])->and($resolved['inputs']['opportunities'][0]['starts_at'])->toBe('2026-11-01T09:30:00Z');
    DB::table('shift_definitions')->update(['start_time' => '02:30']);
    $gap = ($this->capture)(['starts_on' => '2026-03-08', 'ends_on' => '2026-03-08']);
    expect($gap['inputs']['issues'][0]['code'])->toBe('invalid_shift_time');
});

it('uses effective weekly preference revisions and detects later changes without regenerating', function () {
    $save = app(SavePreferenceProfile::class);
    $profile = ['membership_id' => $this->ranger->id, 'season_id' => null, 'week_starts_on' => null,
        'preferred_shift_ids' => [], 'hours_mode' => 'target', 'desired_weekly_minutes' => 1440, 'days_off' => 'consecutive'];
    $id = (string) Str::uuid();
    $save->handle($this->ranger->user, $this->ranger, $id, null, $profile);
    $save->handle($this->ranger->user, $this->ranger, (string) Str::uuid(), null, [...$profile,
        'season_id' => $this->season->id, 'week_starts_on' => '2026-06-01', 'hours_mode' => null,
        'desired_weekly_minutes' => null, 'preferred_shift_ids' => null, 'days_off' => 'separated']);
    $record = ($this->capture)();
    $effective = $record['inputs']['preferences'][$this->ranger->id]['2026-06-01'];
    expect($effective['desired_weekly_minutes'])->toBe(1440)->and($effective['days_off'])->toBe('separated')
        ->and($effective['input_revisions']['default']['revision'])->toBe(1);
    $save->handle($this->ranger->user, $this->ranger, $id, 1, [...$profile, 'desired_weekly_minutes' => 1920]);
    expect(app(PlanningInputs::class)->read($this->manager->user, $this->manager->organization, $record['id'])['stale'])->toBeTrue();
});

it('includes next-week overnight allocations and enforces changed rest settings', function () {
    DB::table('shift_definitions')->update(['start_time' => '22:00']);
    $record = ($this->capture)(['starts_on' => '2026-06-07', 'ends_on' => '2026-06-07', 'minimum_rest_minutes' => 600]);
    expect(array_keys($record['inputs']['weeks']))->toBe(['2026-06-01', '2026-06-08']);
    ($this->commitment)('2026-06-08T21:00:00Z', '2026-06-09T01:00:00Z');
    $record = ($this->capture)(['starts_on' => '2026-06-07', 'ends_on' => '2026-06-07', 'minimum_rest_minutes' => 600]);
    $result = app(PlanningConstraints::class)->evaluate($record['inputs'], ['candidates' => [($this->candidate)($record)]]);
    expect(array_column($result['candidates'][0]['overridable'], 'code'))->toContain('minimum_rest');
    $record = ($this->capture)(['starts_on' => '2026-06-07', 'ends_on' => '2026-06-07', 'minimum_rest_minutes' => 480]);
    $result = app(PlanningConstraints::class)->evaluate($record['inputs'], ['candidates' => [($this->candidate)($record)]]);
    expect($result['candidates'][0]['automatic_eligible'])->toBeTrue();
});

it('treats missing policies as hard unknown inputs and cannot override them', function () {
    DB::table('shift_desirabilities')->delete();
    DB::table('phase_scheduling_policies')->delete();
    $record = ($this->capture)();
    expect($record['inputs']['weeks']['2026-06-01']['policy_minute_sevenths']['planned_cap_minutes'])->toBeNull();
    $result = app(PlanningConstraints::class)->evaluate($record['inputs'], ['candidates' => [($this->candidate)($record, ['override_reason' => 'Cannot bypass missing inputs'])]]);
    expect($result['candidates'][0]['hard'])->toContain('incomplete_configuration')
        ->and($result['candidates'][0]['explicit_override_eligible'])->toBeFalse();
});

it('keeps zero caps distinct from missing policy and allows no silent relaxation', function () {
    DB::table('phase_scheduling_policies')->update(['weekly_target_minutes' => 0, 'planned_cap_minutes' => 0]);
    $record = ($this->capture)();
    expect($record['inputs']['weeks']['2026-06-01']['policy_minute_sevenths']['planned_cap_minutes'])->toBe(0);
    $result = app(PlanningConstraints::class)->evaluate($record['inputs'], ['candidates' => [($this->candidate)($record)]]);
    expect($result['candidates'][0]['automatic_eligible'])->toBeFalse()
        ->and(array_column($result['candidates'][0]['overridable'], 'code'))->toContain('weekly_cap');
});

it('round-trips its migration and enforces tenant linkage', function () {
    $migration = require database_path('migrations/2026_10_14_000001_create_planning_inputs_table.php');
    $migration->down();
    expect(Schema::hasTable('planning_inputs'))->toBeFalse();
    $migration->up();
    $record = ($this->capture)();
    $foreign = OrganizationMembership::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('planning_inputs')->where('id', $record['id'])->update(['organization_id' => $foreign->organization_id])))
        ->toThrow(QueryException::class);
});
