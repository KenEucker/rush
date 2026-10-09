<?php

use App\Exceptions\RevisionConflict;
use App\Models\MemberProfile;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\Identity\UpdateMemberProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->contract = json_decode(file_get_contents(base_path('../docs/contracts/member-profile.example.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->travelTo(new DateTimeImmutable($this->contract['profile']['updated_at']));
    $organization = Organization::factory()->create(['id' => $this->contract['profile']['organization_id']]);
    $membership = OrganizationMembership::factory()->for($organization)->create();
    $this->profile = MemberProfile::factory()->for($membership, 'membership')->create([
        'id' => $this->contract['profile']['id'], 'display_name' => 'Casey Ranger', 'phone' => null,
    ]);
    $this->actor = $membership->user;
    $this->url = "/api/v1/organizations/{$organization->id}/profiles/{$this->profile->id}";
});

it('serves the maintained contract and atomically appends a scoped actor-attributed audit on update', function () {
    $this->actingAs($this->actor)->getJson($this->url)
        ->assertOk()->assertExactJson($this->contract['profile']);
    $this->patchJson($this->url, [...$this->contract['update'], 'revision' => 999, 'actor_id' => 999, 'password' => 'never-audit'])
        ->assertOk()->assertExactJson([
            ...$this->contract['profile'], 'display_name' => 'Casey Updated', 'phone' => '555-0100', 'revision' => 2,
        ])->assertHeader('Cache-Control', 'no-store, private');
    $change = DB::table('member_profile_changes')->sole();
    expect($change->organization_id)->toBe($this->profile->organization_id)
        ->and($change->member_profile_id)->toBe($this->profile->id)
        ->and($change->actor_id)->toBe($this->actor->id)
        ->and($change->revision)->toBe(2)
        ->and(json_decode($change->before, true))->toBe(['display_name' => 'Casey Ranger', 'phone' => null])
        ->and(json_decode($change->after, true))->toBe(['display_name' => 'Casey Updated', 'phone' => '555-0100'])
        ->and((string) $change->occurred_at)->toStartWith('2026-10-09 12:00:00')
        ->and(json_encode($change))->not->toContain('never-audit');
});

it('rejects a second writer and stale replay without changing the accepted record or audit', function () {
    $this->actingAs($this->actor)->patchJson($this->url, $this->contract['update'])->assertOk();
    $this->patchJson($this->url, [...$this->contract['update'], 'display_name' => 'Other device'])
        ->assertConflict()->assertExactJson($this->contract['conflict']);
    expect(fn () => app(UpdateMemberProfile::class)->handle($this->actor, $this->profile, $this->contract['update']))
        ->toThrow(RevisionConflict::class);
    expect($this->profile->fresh()->display_name)->toBe('Casey Updated');
    $this->assertDatabaseCount('member_profile_changes', 1);
});

it('requires an expected revision and validates inside the service for every caller', function (array $invalid, string $field) {
    $this->actingAs($this->actor)->patchJson($this->url, $invalid)
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors($field);
    expect(fn () => app(UpdateMemberProfile::class)->handle($this->actor, $this->profile, $invalid))
        ->toThrow(ValidationException::class);
    expect($this->profile->fresh()->revision)->toBe(1);
    $this->assertDatabaseCount('member_profile_changes', 0);
})->with([
    'missing revision' => [['display_name' => 'Casey'], 'expected_revision'],
    'zero revision' => [['display_name' => 'Casey', 'expected_revision' => 0], 'expected_revision'],
    'fractional revision' => [['display_name' => 'Casey', 'expected_revision' => 1.5], 'expected_revision'],
    'oversized revision' => [['display_name' => 'Casey', 'expected_revision' => 2147483647], 'expected_revision'],
    'blank name' => [['display_name' => '  ', 'expected_revision' => 1], 'display_name'],
    'long phone' => [['display_name' => 'Casey', 'expected_revision' => 1, 'phone' => str_repeat('x', 51)], 'phone'],
]);

it('preserves omitted phone values, allows explicit null and leaves no-op revisions unchanged', function () {
    $this->actingAs($this->actor)->patchJson($this->url, $this->contract['update'])->assertOk();
    $this->patchJson($this->url, ['expected_revision' => 2, 'display_name' => 'Renamed'])
        ->assertOk()->assertJsonPath('phone', '555-0100')->assertJsonPath('revision', 3);
    $this->patchJson($this->url, ['expected_revision' => 3, 'display_name' => 'Renamed'])
        ->assertOk()->assertJsonPath('revision', 3);
    $this->patchJson($this->url, ['expected_revision' => 3, 'display_name' => 'Renamed', 'phone' => null])
        ->assertOk()->assertJsonPath('phone', null)->assertJsonPath('revision', 4);
    $this->assertDatabaseCount('member_profile_changes', 3);
});

it('rolls back both data and revision when appending the audit fails', function () {
    DB::table('member_profile_changes')->insert([
        'id' => (string) Str::uuid(), 'member_profile_id' => $this->profile->id,
        'organization_id' => $this->profile->organization_id, 'actor_id' => $this->actor->id,
        'revision' => 2, 'before' => '{}', 'after' => '{}', 'occurred_at' => now(),
    ]);
    expect(fn () => app(UpdateMemberProfile::class)->handle($this->actor, $this->profile, $this->contract['update']))
        ->toThrow(UniqueConstraintViolationException::class);
    expect($this->profile->fresh()->revision)->toBe(1)->and($this->profile->fresh()->display_name)->toBe('Casey Ranger');
    $this->assertDatabaseCount('member_profile_changes', 1);
});

it('rechecks persisted ownership and revoked membership for non-controller callers', function () {
    $other = MemberProfile::factory()->create();
    $forged = clone $other;
    $forged->organization_membership_id = $this->profile->organization_membership_id;
    $forged->organization_id = $this->profile->organization_id;
    $service = app(UpdateMemberProfile::class);
    expect(fn () => $service->handle($this->actor, $forged, $this->contract['update']))->toThrow(AuthorizationException::class);
    $this->profile->membership->update(['is_active' => false]);
    expect(fn () => $service->handle($this->actor, $this->profile, $this->contract['update']))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('member_profile_changes', 0);
});

it('normalizes denied and missing errors without exposing models or private data', function () {
    $this->getJson($this->url)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    $peer = OrganizationMembership::factory()->create(['organization_id' => $this->profile->organization_id]);
    $this->actingAs($peer->user)->patchJson($this->url, $this->contract['update'])
        ->assertForbidden()->assertJsonPath('code', 'forbidden')->assertJsonMissingPath('exception');
    $this->getJson($this->url.'-missing')->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->assertDatabaseCount('member_profile_changes', 0);
});

it('redacts unexpected exceptions even in debug mode and retains throttle response headers', function () {
    config(['app.debug' => true]);
    Route::get('/api/v1/test-error', fn () => throw new RuntimeException('private database detail'));
    Route::get('/api/v1/test-throttle', fn () => abort(429, 'internal throttle detail', ['Retry-After' => '60']));
    $this->getJson('/api/v1/test-error')->assertStatus(500)->assertExactJson([
        'code' => 'server_error', 'message' => 'The server could not complete your request.', 'errors' => [],
    ])->assertDontSee('private database detail')->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/api/v1/test-throttle')->assertTooManyRequests()->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After', '60');
});

it('keeps audit history linked to the correct organization and prevents silent cascading deletion', function () {
    app(UpdateMemberProfile::class)->handle($this->actor, $this->profile, $this->contract['update']);
    expect(fn () => DB::transaction(fn () => $this->profile->delete()))->toThrow(QueryException::class);
    $this->assertDatabaseCount('member_profile_changes', 1);
});

it('rejects audit records linked to a different organization', function () {
    app(UpdateMemberProfile::class)->handle($this->actor, $this->profile, $this->contract['update']);
    $other = Organization::factory()->create();
    $change = (array) DB::table('member_profile_changes')->sole();
    expect(fn () => DB::transaction(fn () => DB::table('member_profile_changes')->insert([
        ...$change, 'id' => (string) Str::uuid(), 'organization_id' => $other->id, 'revision' => 3,
    ])))->toThrow(QueryException::class);
    $this->assertDatabaseCount('member_profile_changes', 1);
});

it('upgrades existing profiles with revision one and reverses the schema without deleting profiles', function () {
    $migration = require database_path('migrations/2026_10_09_000006_add_profile_revisions_and_audit.php');
    $migration->down();
    expect(Schema::hasColumn('member_profiles', 'revision'))->toBeFalse();
    expect($this->profile->fresh()->display_name)->toBe('Casey Ranger');
    $migration->up();
    expect($this->profile->fresh()->revision)->toBe(1);
    $this->assertDatabaseCount('member_profile_changes', 0);
});
