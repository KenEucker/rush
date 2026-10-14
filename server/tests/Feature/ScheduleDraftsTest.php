<?php

use App\Exceptions\RevisionConflict;
use App\Models\OrganizationMembership;
use App\Services\Availability\SaveUnavailability;
use App\Services\Events\SaveEvent;
use App\Services\Scheduling\PlanningInputs;
use App\Services\Scheduling\SaveOfficialAssignment;
use App\Services\Scheduling\ScheduleDrafts;
use App\Services\Seasons\SavePhaseCoverage;
use App\Services\Seasons\SavePhaseSchedulingPolicy;
use App\Services\Seasons\SaveSeason;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    $this->generate = fn ($snapshot = null) => app(ScheduleDrafts::class)->generate($this->manager->user, $this->manager->organization,
        ($snapshot ?? ($this->capture)())['id'], ['reason' => 'Generate review proposals']);
    $this->saveDraft = fn ($draft, array $candidates, array $extra = []) => app(ScheduleDrafts::class)->save($this->manager->user,
        $this->manager->organization, $draft['id'], ['expected_revision' => $draft['revision'], 'reason' => 'Manager review', 'candidates' => $candidates, ...$extra]);
});

it('generates deterministic whole-shift proposals with audited independent drafts and never changes official records', function () {
    $official = ($this->commitment)('2026-06-02T15:00:00Z', '2026-06-02T23:00:00Z');
    $snapshot = ($this->capture)();
    $a = ($this->generate)($snapshot);
    $b = ($this->generate)($snapshot);
    expect($a['state'])->toBe('draft')->and($a['revision'])->toBe(1)->and($a['assignments'])->toHaveCount(1)
        ->and($a['assignments'][0]['starts_at'])->toBe('2026-06-03T15:00:00Z')
        ->and($a['assignments'][0]['ends_at'])->toBe('2026-06-03T23:00:00Z')
        ->and($a['assignments'][0]['membership_id'])->toBe($b['assignments'][0]['membership_id'])
        ->and($a['id'])->not->toBe($b['id'])->and($a['demand'][0]['maximum_shortfall'])->toBe(0)
        ->and($official->fresh()->revision)->toBe(1);
    $this->assertDatabaseCount('official_assignments', 1);
    $this->assertDatabaseCount('official_assignment_changes', 1);
    $this->assertDatabaseCount('draft_changes', 2);
    expect(DB::table('draft_changes')->where('draft_id', $a['id'])->first()->actor_id)->toBe($this->manager->user_id);
});

it('leaves demand unmet instead of relaxing unavailability overlap cap or rest', function (string $constraint) {
    if ($constraint === 'unavailable') {
        app(SaveUnavailability::class)->handle($this->ranger->user, $this->ranger, (string) Str::uuid(), null,
            ['starts_at' => '2026-06-03T15:00:00Z', 'ends_at' => '2026-06-03T23:00:00Z']);
    } elseif ($constraint === 'overlap') {
        ($this->commitment)('2026-06-03T15:00:00Z', '2026-06-03T23:00:00Z');
    } elseif ($constraint === 'rest') {
        ($this->commitment)('2026-06-03T06:00:00Z', '2026-06-03T08:00:00Z');
    } else {
        ($this->commitment)('2026-06-01T07:00:00Z', '2026-06-02T23:00:00Z');
    }
    $draft = ($this->generate)();
    expect($draft['assignments'])->toBe([])->and($draft['demand'][0]['maximum_shortfall'])->toBe(1);
})->with(['unavailable', 'overlap', 'rest', 'cap']);

it('edits partial intervals reassigns and removes with revisioned history', function () {
    $peer = OrganizationMembership::factory()->create(['role' => 'ranger', 'organization_id' => $this->manager->organization_id]);
    $draft = ($this->generate)();
    $candidate = ['membership_id' => $peer->id, 'opportunity_id' => $draft['assignments'][0]['opportunity_id'],
        'starts_at' => '2026-06-03T16:00:00Z', 'ends_at' => '2026-06-03T20:00:00Z'];
    $edited = ($this->saveDraft)($draft, [$candidate]);
    expect($edited['revision'])->toBe(2)->and($edited['assignments'][0]['membership_id'])->toBe($peer->id)
        ->and($edited['assignments'][0]['starts_at'])->toBe($candidate['starts_at'])
        ->and($edited['demand'][0]['segments'])->toHaveCount(3)->and($edited['demand'][0]['maximum_shortfall'])->toBe(1);
    $removed = ($this->saveDraft)($edited, []);
    expect($removed['assignments'])->toBe([])->and($removed['revision'])->toBe(3);
    $this->assertDatabaseCount('draft_changes', 3);
    $this->assertDatabaseCount('official_assignments', 0);
    expect(json_decode(DB::table('draft_changes')->where('draft_id', $draft['id'])->where('revision', 2)->value('before'), true))->toHaveCount(1);
});

it('requires persisted explicit reasons for overrides and cannot override structural errors', function () {
    app(SaveUnavailability::class)->handle($this->ranger->user, $this->ranger, (string) Str::uuid(), null,
        ['starts_at' => '2026-06-03T15:00:00Z', 'ends_at' => '2026-06-03T23:00:00Z']);
    $snapshot = ($this->capture)();
    $draft = ($this->generate)($snapshot);
    $url = '/api/v1/organizations/'.$this->manager->organization_id.'/drafts/'.$draft['id'];
    $body = ['expected_revision' => 1, 'reason' => 'Review', 'candidates' => [($this->candidate)($snapshot)]];
    $this->actingAs($this->manager->user)->putJson($url, $body)->assertUnprocessable();
    $body['candidates'][0]['override_reason'] = 'Explicit emergency plan';
    $this->putJson($url, $body)->assertOk()->assertJsonPath('assignments.0.override_reason', 'Explicit emergency plan')
        ->assertJsonPath('assignments.0.evaluation.overridable.0.code', 'unavailable')->assertJsonPath('demand.0.maximum_shortfall', 1);
    $body['expected_revision'] = 2;
    $body['candidates'][0]['membership_id'] = $this->manager->id;
    $this->putJson($url, $body)->assertUnprocessable();
    $this->assertDatabaseCount('draft_changes', 2);
});

it('rejects malformed outside and empty partial intervals without partial writes', function (array $extra) {
    $snapshot = ($this->capture)();
    $draft = ($this->generate)($snapshot);
    $this->actingAs($this->manager->user)->putJson('/api/v1/organizations/'.$this->manager->organization_id.'/drafts/'.$draft['id'],
        ['expected_revision' => 1, 'reason' => 'Invalid edit', 'candidates' => [($this->candidate)($snapshot, $extra)]])->assertUnprocessable();
    $this->assertDatabaseCount('draft_changes', 1);
    expect(DB::table('schedule_drafts')->find($draft['id'])->revision)->toBe(1);
})->with([
    [['starts_at' => '2026-06-03T14:00:00Z', 'ends_at' => '2026-06-03T20:00:00Z']],
    [['starts_at' => '2026-06-03T16:00:00Z', 'ends_at' => '2026-06-03T16:00:00Z']],
    [['starts_at' => '2026-06-03T16:00:00Z']], [['starts_at' => 'not a date', 'ends_at' => '2026-06-03T20:00:00Z']],
    [['membership_id' => '00000000-0000-4000-8000-000000000000', 'override_reason' => 'No bypass']],
]);

it('enforces current management tenant authorization on all draft endpoints and Orchid', function () {
    $snapshot = ($this->capture)();
    $draft = ($this->generate)($snapshot);
    $base = '/api/v1/organizations/'.$this->manager->organization_id;
    $screen = route('platform.drafts', [$this->manager->organization_id, $this->season->id, $draft['id']]);
    Auth::forgetGuards();
    $this->actingAs($this->manager->user, 'web')->get($screen)->assertOk()->assertSee('Draft proposals');
    foreach ([$this->ranger, OrganizationMembership::factory()->create(['role' => 'management'])] as $member) {
        Auth::forgetGuards();
        $this->actingAs($member->user)->getJson($base.'/drafts/'.$draft['id'])->assertForbidden();
        $this->putJson($base.'/drafts/'.$draft['id'], ['candidates' => []])->assertForbidden();
        $this->postJson($base.'/planning-inputs/'.$snapshot['id'].'/drafts', ['reason' => 'Unauthorized'])->assertForbidden();
        $this->get($screen)->assertForbidden();
    }
    $other = OrganizationMembership::factory()->create(['role' => 'management']);
    Auth::forgetGuards();
    $this->actingAs($other->user)->getJson('/api/v1/organizations/'.$other->organization_id.'/drafts/'.$draft['id'])->assertNotFound();
    $this->manager->update(['is_active' => false]);
    Auth::forgetGuards();
    $this->actingAs($this->manager->user)->getJson($base.'/drafts/'.$draft['id'])->assertForbidden();
});

it('rejects stale draft revisions and stale input snapshots while preserving the saved draft', function () {
    $snapshot = ($this->capture)();
    $draft = ($this->generate)($snapshot);
    ($this->saveDraft)($draft, []);
    $base = '/api/v1/organizations/'.$this->manager->organization_id;
    $this->actingAs($this->manager->user)->putJson($base.'/drafts/'.$draft['id'], ['expected_revision' => 1, 'reason' => 'Stale', 'candidates' => []])->assertConflict();
    $this->ranger->update(['is_active' => false]);
    $this->getJson($base.'/drafts/'.$draft['id'])->assertOk()->assertJsonPath('snapshot.stale', true)->assertJsonPath('revision', 2);
    $this->putJson($base.'/drafts/'.$draft['id'], ['expected_revision' => 2, 'reason' => 'Stale inputs', 'candidates' => []])->assertConflict();
    $this->postJson($base.'/planning-inputs/'.$snapshot['id'].'/drafts', ['reason' => 'Stale inputs'])->assertConflict();
    $this->assertDatabaseCount('draft_changes', 2);
});

it('generates event proposals without creating official attendance and counts scheduled shifts only when configured', function (bool $countsScheduled, int $eventAssignments) {
    OrganizationMembership::factory()->create(['role' => 'ranger', 'organization_id' => $this->manager->organization_id]);
    $event = app(SaveEvent::class)->handle($this->manager->user, $this->manager->organization, $this->season->id, (string) Str::uuid(), [
        'name' => 'Meeting', 'expected_revision' => null, 'expected_season_revision' => 1,
        'starts_local' => '2026-06-03 10:00:00', 'ends_local' => '2026-06-03 11:00:00', 'paid' => true,
        'counts_scheduled' => $countsScheduled, 'target_type' => 'number', 'target_value' => 1,
        'attendance_membership_ids' => [], 'reason' => 'Meeting']);
    $draft = ($this->generate)();
    expect(collect($draft['assignments'])->filter(fn ($a) => $a['context']['kind'] === 'event'))->toHaveCount($eventAssignments);
    expect(collect($draft['demand'])->first(fn ($d) => $d['opportunity']['id'] === $event->id)['maximum_shortfall'])->toBe(0);
    $this->assertDatabaseCount('event_attendances', 0);
    $this->assertDatabaseCount('official_assignments', 0);
    expect($event->fresh()->revision)->toBe(1);
})->with([[true, 0], [false, 1]]);

it('rolls back assignments and revision when audit persistence fails', function () {
    $draft = ($this->generate)();
    DB::table('draft_changes')->insert(['id' => (string) Str::uuid(), 'draft_id' => $draft['id'], 'actor_id' => $this->manager->user_id,
        'revision' => 2, 'reason' => 'Force unique collision', 'before' => null, 'after' => '[]', 'occurred_at' => now()]);
    expect(fn () => ($this->saveDraft)($draft, []))->toThrow(QueryException::class);
    expect(DB::table('schedule_drafts')->find($draft['id'])->revision)->toBe(1);
    $this->assertDatabaseCount('draft_assignments', 1);
});

it('retains whole overnight shifts across the DST fold and accepts bounded manual partials', function () {
    DB::table('shift_definitions')->update(['start_time' => '22:00', 'duration_minutes' => 480]);
    $draft = ($this->generate)(($this->capture)(['starts_on' => '2026-10-31', 'ends_on' => '2026-10-31']));
    expect($draft['assignments'][0]['starts_at'])->toBe('2026-11-01T05:00:00Z')
        ->and($draft['assignments'][0]['ends_at'])->toBe('2026-11-01T13:00:00Z');
    $assignment = $draft['assignments'][0];
    $edited = app(ScheduleDrafts::class)->edit($this->manager->user, $this->manager->organization, $draft['id'], [
        'assignment_id' => $assignment['id'], 'membership_id' => $assignment['membership_id'], 'opportunity_id' => $assignment['opportunity_id'],
        'expected_revision' => 1, 'reason' => 'Fold partial', 'starts_local' => '2026-11-01 01:30:00', 'ends_local' => '2026-11-01 04:30:00',
        'starts_offset' => '-07:00', 'ends_offset' => '-08:00']);
    expect($edited['assignments'][0]['starts_at'])->toBe('2026-11-01T08:30:00Z')
        ->and($edited['assignments'][0]['ends_at'])->toBe('2026-11-01T12:30:00Z');
});

it('counts existing published coverage without adding replacements or mutating it', function () {
    $snapshot = ($this->capture)();
    $opportunity = $snapshot['inputs']['opportunities'][0];
    $official = app(SaveOfficialAssignment::class)->handle($this->manager->user, $this->manager->organization, (string) Str::uuid(), [
        'membership_id' => $this->ranger->id, 'expected_revision' => null, 'starts_at' => $opportunity['starts_at'], 'ends_at' => $opportunity['ends_at'],
        'reason' => 'Published whole shift', 'shift' => ['phase_id' => $opportunity['phase_id'], 'shift_id' => $opportunity['shift_id'],
            'target_id' => $opportunity['coverage']['area_id'], 'model' => 'dedicated', 'date' => '2026-06-03',
            'expected_coverage_revision' => 1, 'expected_season_revision' => 1]]);
    $draft = ($this->generate)();
    expect($draft['assignments'])->toBe([])->and($draft['demand'][0]['maximum_shortfall'])->toBe(0)
        ->and($official->fresh()->revision)->toBe(1);
    $this->assertDatabaseCount('official_assignment_changes', 1);
});

it('does not count partial scheduled coverage as a whole event and rejects partial event proposals', function () {
    OrganizationMembership::factory()->create(['role' => 'ranger', 'organization_id' => $this->manager->organization_id]);
    $event = app(SaveEvent::class)->handle($this->manager->user, $this->manager->organization, $this->season->id, (string) Str::uuid(), [
        'name' => 'Long meeting', 'expected_revision' => null, 'expected_season_revision' => 1,
        'starts_local' => '2026-06-03 07:00:00', 'ends_local' => '2026-06-03 10:00:00', 'paid' => true,
        'counts_scheduled' => true, 'target_type' => 'percentage', 'target_value' => 50,
        'attendance_membership_ids' => [], 'reason' => 'Meeting']);
    $draft = ($this->generate)();
    expect(collect($draft['assignments'])->filter(fn ($a) => $a['context']['kind'] === 'event'))->toHaveCount(1);
    $eventAssignment = collect($draft['assignments'])->firstWhere('opportunity_id', $event->id);
    expect(fn () => ($this->saveDraft)($draft, [['membership_id' => $eventAssignment['membership_id'], 'opportunity_id' => $event->id,
        'starts_at' => '2026-06-03T15:00:00Z', 'ends_at' => '2026-06-03T17:00:00Z', 'override_reason' => 'Cannot shorten event']]))
        ->toThrow(ValidationException::class);
});

it('fills configurable 24 hour staffing with whole shifts and exposes low-roster shortfalls', function () {
    OrganizationMembership::factory()->count(2)->create(['role' => 'ranger', 'organization_id' => $this->manager->organization_id]);
    $phase = $this->season->phases->first();
    $coverage = DB::table('coverage_staffing')->where('phase_id', $phase->id)->first();
    foreach (['00:00', '16:00'] as $time) {
        $shift = (string) Str::uuid();
        DB::table('shift_definitions')->insert(['id' => $shift, 'phase_id' => $phase->id, 'name' => $time, 'start_time' => $time, 'duration_minutes' => 480]);
        DB::table('coverage_staffing')->insert(['id' => (string) Str::uuid(), 'phase_id' => $phase->id, 'shift_id' => $shift,
            'area_id' => $coverage->area_id, 'model' => 'dedicated', 'positions' => 1]);
        DB::table('shift_desirabilities')->insert(['id' => (string) Str::uuid(), 'phase_id' => $phase->id, 'shift_id' => $shift, 'classification' => 'neutral', 'scope' => 'baseline', 'scope_key' => '*']);
    }
    $draft = ($this->generate)();
    expect($draft['assignments'])->toHaveCount(3)->and(array_sum(array_column($draft['demand'], 'maximum_shortfall')))->toBe(0);
    DB::table('coverage_staffing')->update(['positions' => 4]);
    $short = ($this->generate)();
    expect(array_sum(array_column($short['demand'], 'maximum_shortfall')))->toBeGreaterThan(0);
});

it('round-trips draft tables while preserving inputs and enforces tenant and audit foreign keys', function () {
    $snapshot = ($this->capture)();
    $migration = require database_path('migrations/2026_10_15_000001_create_schedule_drafts.php');
    $migration->down();
    $this->assertDatabaseHas('planning_inputs', ['id' => $snapshot['id']]);
    $migration->up();
    $draft = ($this->generate)($snapshot);
    $outsider = OrganizationMembership::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('draft_assignments')->where('draft_id', $draft['id'])->update(['membership_id' => $outsider->id])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('schedule_drafts')->where('id', $draft['id'])->update(['organization_id' => $outsider->organization_id])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('schedule_drafts')->where('id', $draft['id'])->delete()))->toThrow(QueryException::class);
});

it('keeps historical drafts readable but prevents archived season edits and generation', function () {
    $snapshot = ($this->capture)();
    $draft = ($this->generate)($snapshot);
    $this->travelTo(CarbonImmutable::parse('2027-02-01T00:00:00Z'));
    $base = '/api/v1/organizations/'.$this->manager->organization_id;
    $this->actingAs($this->manager->user)->getJson($base.'/drafts/'.$draft['id'])->assertOk();
    $this->putJson($base.'/drafts/'.$draft['id'], ['expected_revision' => 1, 'reason' => 'Archive', 'candidates' => []])->assertForbidden();
    $this->postJson($base.'/planning-inputs/'.$snapshot['id'].'/drafts', ['reason' => 'Archive'])->assertForbidden();
});

it('reports a revision conflict when another manager removes the assignment being edited', function () {
    $draft = ($this->generate)();
    $assignment = $draft['assignments'][0];
    ($this->saveDraft)($draft, []);
    expect(fn () => app(ScheduleDrafts::class)->edit($this->manager->user, $this->manager->organization, $draft['id'], [
        'expected_revision' => 1, 'reason' => 'Preserve stale edit', 'assignment_id' => $assignment['id'],
        'membership_id' => $assignment['membership_id'], 'opportunity_id' => $assignment['opportunity_id'],
    ]))->toThrow(RevisionConflict::class);
});
