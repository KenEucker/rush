<?php

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\Phase;
use App\Models\Season;
use App\Services\Preferences\EffectivePreferences;
use App\Services\Preferences\SavePreferenceProfile;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = OrganizationMembership::factory()->create(['role' => OrganizationRole::Ranger]);
    $this->season = Season::create(['organization_id' => $this->member->organization_id, 'name' => 'Fall', 'starts_on' => '2026-10-01', 'ends_on' => '2027-01-10', 'timezone' => 'America/Los_Angeles', 'week_starts_on' => 1, 'revision' => 1]);
    $phase = Phase::create(['season_id' => $this->season->id, 'name' => 'Fall', 'starts_on' => '2026-10-01', 'ends_on' => '2027-01-10']);
    $this->shift = (string) Str::uuid();
    DB::table('shift_definitions')->insert(['id' => $this->shift, 'phase_id' => $phase->id, 'name' => 'Evening', 'start_time' => '17:00', 'duration_minutes' => 480]);
    $this->base = "/api/v1/organizations/{$this->member->organization_id}/sync";
    $this->input = ['membership_id' => $this->member->id, 'season_id' => null, 'week_starts_on' => null, 'preferred_shift_ids' => [$this->shift], 'hours_mode' => 'target', 'desired_weekly_minutes' => 1920, 'days_off' => 'consecutive'];
    $this->operation = ['operation_id' => (string) Str::uuid(), 'type' => 'preference_profile.save', 'record_id' => (string) Str::uuid(), 'expected_revision' => null, 'payload' => $this->input];
});

it('creates an audited default once and exposes only own preferences and minimal configuration', function () {
    $result = $this->actingAs($this->member->user)->postJson($this->base.'/push', $this->operation)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('preference_profile.revision', 1)->json();
    $this->postJson($this->base.'/push', $this->operation)->assertExactJson($result);
    $this->assertDatabaseCount('preference_profiles', 1);
    $this->assertDatabaseCount('preference_profile_changes', 1);
    $page = $this->postJson($this->base.'/pull', ['limit' => 1])->assertJsonPath('has_more', true)->json();
    expect($page['changes'][0]['value']['preferred_shift_ids'])->toBe([$this->shift]);
    $catalog = $this->postJson($this->base.'/pull', ['checkpoint' => $page['checkpoint']])->assertJsonPath('has_more', false)->json('changes.0.value');
    expect($catalog['shifts'][0]['name'])->toBe('Evening')->and($catalog)->not->toHaveKey('staffing');
    $peer = OrganizationMembership::factory()->create(['organization_id' => $this->member->organization_id, 'role' => 'ranger']);
    $this->actingAs($peer->user)->postJson($this->base.'/pull')->assertJsonCount(1, 'changes')->assertJsonPath('changes.0.record_type', 'preference_catalog');
    $this->member->update(['role' => 'management']);
    $this->actingAs($this->member->user)->postJson($this->base.'/pull', ['checkpoint' => $page['checkpoint']])->assertConflict();
    $this->postJson($this->base.'/pull')->assertJsonCount(0, 'changes');
});

it('inherits unspecified fields dynamically and returns to default at the local week boundary', function () {
    $service = app(SavePreferenceProfile::class);
    $default = $service->handle($this->member->user, $this->member, $this->operation['record_id'], null, $this->input);
    $weekly = [...$this->input, 'season_id' => $this->season->id, 'week_starts_on' => '2026-10-26', 'preferred_shift_ids' => [], 'hours_mode' => null, 'desired_weekly_minutes' => null, 'days_off' => 'separated'];
    $service->handle($this->member->user, $this->member, (string) Str::uuid(), null, $weekly);
    $resolve = fn ($time) => app(EffectivePreferences::class)->forInstant($this->member, $this->season, CarbonImmutable::parse($time));
    $during = $resolve('2026-11-02T07:59:59Z');
    expect($during['preferred_shift_ids'])->toBe([])->and($during['days_off'])->toBe('separated')->and($during['desired_weekly_minutes'])->toBe(1920);
    $service->handle($this->member->user, $this->member, $default->id, 1, [...$this->input, 'hours_mode' => 'maximum', 'desired_weekly_minutes' => null]);
    expect($resolve('2026-11-02T07:59:59Z')['hours_mode'])->toBe('maximum');
    $after = $resolve('2026-11-02T08:00:00Z');
    expect($after['week_starts_on'])->toBe('2026-11-02')->and($after['preferred_shift_ids'])->toBe([$this->shift])->and($after['days_off'])->toBe('consecutive')->and($after['input_revisions']['weekly'])->toBeNull();
    expect($during['input_revisions']['default']['revision'])->toBe(1)->and($after['input_revisions']['default']['revision'])->toBe(2);
});

it('supports explicit neutral maximum and all-inherited overrides including year boundaries', function () {
    $service = app(SavePreferenceProfile::class);
    $weekly = [...$this->input, 'season_id' => $this->season->id, 'week_starts_on' => '2026-12-28', 'preferred_shift_ids' => null, 'hours_mode' => null, 'desired_weekly_minutes' => null, 'days_off' => null];
    $record = $service->handle($this->member->user, $this->member, (string) Str::uuid(), null, $weekly);
    $result = app(EffectivePreferences::class)->forInstant($this->member, $this->season, CarbonImmutable::parse('2027-01-01T12:00:00Z'));
    expect($result['hours_mode'])->toBe('none')->and($result['preferred_shift_ids'])->toBe([]);
    $record = $service->handle($this->member->user, $this->member, $record->id, 1, [...$weekly, 'hours_mode' => 'maximum', 'days_off' => 'none']);
    expect($record->hours_mode)->toBe('maximum')->and($record->days_off)->toBe('none');
});

it('rejects invalid fields durably without a profile or audit', function (array $changes) {
    $op = [...$this->operation, 'payload' => [...$this->input, ...$changes]];
    $result = $this->actingAs($this->member->user)->postJson($this->base.'/push', $op)->assertOk()->assertJsonPath('status', 'rejected')->json();
    $this->postJson($this->base.'/push', $op)->assertExactJson($result);
    $this->assertDatabaseCount('preference_profiles', 0);
    $this->assertDatabaseCount('preference_profile_changes', 0);
})->with([
    [['preferred_shift_ids' => null]], [['hours_mode' => null]], [['days_off' => null]],
    [['hours_mode' => 'avoid']], [['days_off' => 'forbidden']], [['desired_weekly_minutes' => -1]],
    [['desired_weekly_minutes' => 10081]], [['desired_weekly_minutes' => 30.5]],
    [['hours_mode' => 'maximum']], [['hours_mode' => 'target', 'desired_weekly_minutes' => null]],
    [['preferred_shift_ids' => ['01920000-0000-7000-8000-000000000001']]],
    [['preferred_shift_ids' => ['named' => '01920000-0000-7000-8000-000000000001']]],
    [['week_starts_on' => '2026-10-26']], [['week_starts_on' => '2026-02-30']],
]);

it('validates season scope week starts applicable shifts and immutable week identity', function () {
    $weekly = [...$this->input, 'season_id' => $this->season->id, 'week_starts_on' => '2026-10-26'];
    $this->actingAs($this->member->user);
    foreach (['2026-10-27', '2026-09-21', '2027-01-11'] as $date) {
        $this->postJson($this->base.'/push', [...$this->operation, 'operation_id' => (string) Str::uuid(), 'payload' => [...$weekly, 'week_starts_on' => $date]])->assertJsonPath('status', 'rejected');
    }
    $this->postJson($this->base.'/push', [...$this->operation, 'payload' => $weekly])->assertJsonPath('status', 'accepted');
    $this->postJson($this->base.'/push', [...$this->operation, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 1, 'payload' => [...$weekly, 'week_starts_on' => '2026-11-02']])->assertJsonPath('status', 'rejected');
    $this->postJson($this->base.'/push', [...$this->operation, 'operation_id' => (string) Str::uuid(), 'record_id' => (string) Str::uuid(), 'payload' => [...$weekly, 'season_id' => (string) Str::uuid()]])->assertJsonPath('status', 'rejected');
});

it('preserves revisions on stale edits duplicate scope creation and reused operation IDs', function () {
    $this->actingAs($this->member->user)->postJson($this->base.'/push', $this->operation)->assertJsonPath('status', 'accepted');
    $update = [...$this->operation, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 1, 'payload' => [...$this->input, 'desired_weekly_minutes' => 1440]];
    $this->postJson($this->base.'/push', $update)->assertJsonPath('preference_profile.revision', 2);
    $this->postJson($this->base.'/push', [...$update, 'operation_id' => (string) Str::uuid()])->assertJsonPath('status', 'conflict');
    $this->postJson($this->base.'/push', [...$this->operation, 'operation_id' => (string) Str::uuid(), 'record_id' => (string) Str::uuid()])->assertJsonPath('status', 'conflict');
    $this->postJson($this->base.'/push', [...$update, 'payload' => $this->input])->assertConflict();
    $this->assertDatabaseCount('preference_profiles', 1);
    $this->assertDatabaseCount('preference_profile_changes', 2);
});

it('enforces guest role owner organization and revoked access even for receipt replay', function () {
    $this->postJson($this->base.'/push', $this->operation)->assertUnauthorized();
    $this->actingAs($this->member->user)->postJson($this->base.'/push', $this->operation)->assertJsonPath('status', 'accepted');
    $peer = OrganizationMembership::factory()->create(['organization_id' => $this->member->organization_id, 'role' => 'ranger']);
    $this->actingAs($peer->user)->postJson($this->base.'/push', $this->operation)->assertForbidden();
    $this->postJson($this->base.'/push', [...$this->operation, 'payload' => [...$this->input, 'membership_id' => $peer->id]])->assertForbidden();
    expect(fn () => app(SavePreferenceProfile::class)->handle($peer->user, $this->member, $this->operation['record_id'], 1, $this->input))->toThrow(AuthorizationException::class);
    $foreign = OrganizationMembership::factory()->create();
    $this->actingAs($foreign->user)->postJson($this->base.'/push', $this->operation)->assertForbidden();
    $this->member->update(['role' => 'management']);
    $this->actingAs($this->member->user)->postJson($this->base.'/push', $this->operation)->assertForbidden();
    $this->member->update(['role' => 'ranger', 'is_active' => false]);
    $this->postJson($this->base.'/push', $this->operation)->assertForbidden();
});

it('rolls back the profile audit and receipt together when auditing fails', function () {
    $failed = false;
    DB::connection()->beforeExecuting(function ($query) use (&$failed) {
        if (! $failed && str_contains($query, 'insert into "preference_profile_changes"')) {
            $failed = true;
            throw new RuntimeException('Audit storage failed');
        }
    });
    $this->actingAs($this->member->user)->postJson($this->base.'/push', $this->operation)->assertStatus(500);
    $this->assertDatabaseCount('preference_profiles', 0);
    $this->assertDatabaseCount('sync_operations', 0);
    $this->postJson($this->base.'/push', $this->operation)->assertJsonPath('status', 'accepted');
});
