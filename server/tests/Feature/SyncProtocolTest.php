<?php

use App\Enums\OrganizationRole;
use App\Models\MemberProfile;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\Identity\UpdateMemberProfile;
use App\Services\Sync\PullChanges;
use App\Services\Sync\PushOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->profile = MemberProfile::factory()->create();
    $this->membership = $this->profile->membership;
    $this->actor = $this->membership->user;
    $this->organization = $this->profile->organization;
    $this->base = "/api/v1/organizations/{$this->organization->id}/sync";
    $this->operation = ['operation_id' => (string) Str::uuid(), 'type' => 'member_profile.update',
        'record_id' => $this->profile->id, 'expected_revision' => 1,
        'payload' => ['display_name' => 'Offline intent', 'phone' => null]];
});

it('replays an accepted command after a lost response and later changes without duplicate effects', function () {
    $first = $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)
        ->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('profile.revision', 2)
        ->assertHeader('Cache-Control', 'no-store, private')->json();
    app(UpdateMemberProfile::class)->handle($this->actor, $this->profile, ['expected_revision' => 2, 'display_name' => 'Newer device']);
    $reordered = [...$this->operation, 'payload' => ['phone' => null, 'display_name' => 'Offline intent']];
    $this->postJson($this->base.'/push', $reordered)->assertOk()->assertExactJson($first);
    expect($this->profile->fresh()->display_name)->toBe('Newer device');
    $this->assertDatabaseCount('sync_operations', 1);
    $this->assertDatabaseCount('member_profile_changes', 2);
});

it('matches the maintained Client wire example', function () {
    $contract = json_decode(file_get_contents(base_path('../docs/contracts/sync.example.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->travelTo(new DateTimeImmutable($contract['accepted']['profile']['updated_at']));
    $organization = Organization::factory()->create(['id' => $contract['accepted']['profile']['organization_id']]);
    $membership = OrganizationMembership::factory()->for($organization)->create();
    MemberProfile::factory()->for($membership, 'membership')->create(['id' => $contract['operation']['record_id']]);
    $this->actingAs($membership->user)->postJson("/api/v1/organizations/{$organization->id}/sync/push", $contract['operation'])
        ->assertOk()->assertExactJson($contract['accepted']);
});

it('rejects operation ID reuse and isolates the same ID across accounts', function () {
    $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)->assertOk();
    $this->postJson($this->base.'/push', [...$this->operation, 'payload' => ['display_name' => 'Changed intent']])
        ->assertConflict()->assertJsonPath('code', 'operation_id_reused');
    $peer = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
    $profile = MemberProfile::factory()->for($peer, 'membership')->create();
    $this->actingAs($peer->user)->postJson($this->base.'/push', [...$this->operation, 'record_id' => $profile->id])
        ->assertOk()->assertJsonPath('status', 'accepted');
    $this->assertDatabaseCount('sync_operations', 2);
});

it('retains conflict and validation results across replay without changing domain state', function () {
    app(UpdateMemberProfile::class)->handle($this->actor, $this->profile, ['expected_revision' => 1, 'display_name' => 'Server wins']);
    $conflict = $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)
        ->assertOk()->assertJsonPath('status', 'conflict')->assertJsonPath('error.code', 'revision_conflict')->json();
    $this->postJson($this->base.'/push', $this->operation)->assertExactJson($conflict);
    $invalid = [...$this->operation, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 2, 'payload' => ['display_name' => '']];
    $rejected = $this->postJson($this->base.'/push', $invalid)->assertOk()->assertJsonPath('status', 'rejected')->json();
    $this->postJson($this->base.'/push', $invalid)->assertExactJson($rejected);
    expect($this->profile->fresh()->display_name)->toBe('Server wins');
    $this->assertDatabaseCount('member_profile_changes', 1);
    $this->assertDatabaseCount('sync_operations', 2);
});

it('rejects malformed envelopes and unknown commands before storing receipts', function (array $override) {
    $this->actingAs($this->actor)->postJson($this->base.'/push', [...$this->operation, ...$override])
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
    $this->assertDatabaseCount('sync_operations', 0);
    $this->assertDatabaseCount('member_profile_changes', 0);
})->with([
    [['operation_id' => 'not-a-uuid']], [['expected_revision' => 0]], [['expected_revision' => 1.5]],
    [['type' => 'schedule.publish']], [['payload' => ['display_name' => 'Name', 'password' => 'secret']]],
]);

it('revalidates authentication organization and ownership on push pull and replay', function () {
    $this->postJson($this->base.'/push', $this->operation)->assertUnauthorized();
    $this->postJson($this->base.'/pull')->assertUnauthorized();
    $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)->assertOk();
    $peer = OrganizationMembership::factory()->management()->create(['organization_id' => $this->organization->id]);
    $this->actingAs($peer->user)->postJson($this->base.'/push', $this->operation)->assertForbidden();
    $foreign = MemberProfile::factory()->create();
    $this->actingAs($foreign->membership->user)->postJson($this->base.'/pull')->assertForbidden();
    $this->postJson($this->base.'/push', $this->operation)->assertForbidden();
    $this->membership->update(['is_active' => false]);
    $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)->assertForbidden();
    $this->postJson($this->base.'/pull')->assertForbidden();
    $this->assertDatabaseCount('sync_operations', 1);
});

it('rolls back the profile audit and receipt on infrastructure failure and permits safe retry', function () {
    $failed = false;
    DB::connection()->beforeExecuting(function ($query) use (&$failed) {
        if (! $failed && str_contains($query, 'insert into "sync_operations"')) {
            $failed = true;
            throw new RuntimeException('simulated receipt storage failure');
        }
    });
    $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)->assertStatus(500);
    expect($this->profile->fresh()->revision)->toBe(1);
    $this->assertDatabaseCount('member_profile_changes', 0);
    $this->assertDatabaseCount('sync_operations', 0);
    $this->postJson($this->base.'/push', $this->operation)->assertOk()->assertJsonPath('status', 'accepted');
    $this->assertDatabaseCount('member_profile_changes', 1);
});

it('keeps valid no-op commands idempotent without inventing a revision or audit', function () {
    $input = [...$this->operation, 'payload' => ['display_name' => $this->profile->display_name]];
    $result = app(PushOperation::class)->handle($this->actor, $this->organization, $input);
    expect($result['profile']['revision'])->toBe(1);
    expect(app(PushOperation::class)->handle($this->actor, $this->organization, $input))->toBe($result);
    $this->assertDatabaseCount('member_profile_changes', 0);
});

it('bootstraps only authorized records and captures online writes independent of device time', function () {
    MemberProfile::factory()->for(OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]), 'membership')->create();
    MemberProfile::factory()->create();
    $page = $this->actingAs($this->actor)->postJson($this->base.'/pull')->assertOk()->assertJsonCount(1, 'changes')->json();
    expect($page['changes'][0]['record_id'])->toBe($this->profile->id);
    $this->postJson($this->base.'/pull', ['checkpoint' => $page['checkpoint']])->assertJsonCount(0, 'changes');
    $this->travel(-1)->days();
    app(UpdateMemberProfile::class)->handle($this->actor, $this->profile, ['expected_revision' => 1, 'display_name' => 'Changed online']);
    $next = $this->postJson($this->base.'/pull', ['checkpoint' => $page['checkpoint']])->assertOk()
        ->assertJsonPath('changes.0.value.revision', 2)->json();
    expect($next['changes'][0]['sequence'])->toBeGreaterThan($page['changes'][0]['sequence']);
});

it('resumes bounded pages without skipping edits that arrive during pagination', function () {
    $this->membership->update(['role' => OrganizationRole::Management]);
    foreach (range(1, 3) as $index) {
        MemberProfile::factory()->for(OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]), 'membership')->create();
    }
    $service = app(PullChanges::class);
    $first = $service->handle($this->actor, $this->organization, ['limit' => 1]);
    expect($first['has_more'])->toBeTrue();
    app(UpdateMemberProfile::class)->handle($this->actor, $this->profile, ['expected_revision' => 1, 'display_name' => 'During pagination']);
    $second = $service->handle($this->actor, $this->organization, ['checkpoint' => $first['checkpoint'], 'limit' => 1]);
    $replay = $service->handle($this->actor, $this->organization, ['checkpoint' => $first['checkpoint'], 'limit' => 1]);
    expect($replay['changes'])->toBe($second['changes']);
    $last = $service->handle($this->actor, $this->organization, ['checkpoint' => $second['checkpoint']]);
    expect($last['has_more'])->toBeFalse()->and(count($last['changes']))->toBe(2);
    $next = $service->handle($this->actor, $this->organization, ['checkpoint' => $last['checkpoint']]);
    expect($next['changes'])->toHaveCount(1)->and($next['changes'][0]['value']['revision'])->toBe(2);
});

it('returns tombstones for deletions and redacts removed records from historic pages', function () {
    $first = $this->actingAs($this->actor)->postJson($this->base.'/pull')->assertOk()->json();
    $this->profile->delete();
    $this->postJson($this->base.'/pull', ['checkpoint' => $first['checkpoint']])->assertOk()
        ->assertJsonPath('changes.0.record_id', $this->profile->id)->assertJsonPath('changes.0.value', null);
    $this->postJson($this->base.'/pull')->assertOk()->assertJsonPath('changes.0.value', null);
});

it('invalidates tampered foreign future and role-stale checkpoints without exposing history', function () {
    $this->membership->update(['role' => OrganizationRole::Management]);
    $page = $this->actingAs($this->actor)->postJson($this->base.'/pull')->assertOk()->json();
    $this->postJson($this->base.'/pull', ['checkpoint' => 'bad-token'])->assertConflict()->assertJsonPath('code', 'checkpoint_invalid');
    $token = json_decode(Crypt::decryptString($page['checkpoint']), true);
    $future = Crypt::encryptString(json_encode([...$token, 'upper' => 100000]));
    $this->postJson($this->base.'/pull', ['checkpoint' => $future])->assertConflict();
    $peer = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
    $this->actingAs($peer->user)->postJson($this->base.'/pull', ['checkpoint' => $page['checkpoint']])->assertConflict();
    $this->membership->update(['role' => OrganizationRole::Ranger]);
    $this->actingAs($this->actor)->postJson($this->base.'/pull', ['checkpoint' => $page['checkpoint']])->assertConflict();
    $reset = $this->postJson($this->base.'/pull')->assertOk()->assertJsonCount(1, 'changes')->json();
    expect($reset['changes'][0]['record_id'])->toBe($this->profile->id);
    $this->postJson($this->base.'/pull', ['checkpoint' => $page['checkpoint']])->assertConflict();
});

it('validates bounded pull requests and rolls back projection and sequence on failure', function () {
    $this->actingAs($this->actor)->postJson($this->base.'/pull', ['limit' => 101])->assertUnprocessable();
    $failed = false;
    DB::connection()->beforeExecuting(function ($query) use (&$failed) {
        if (! $failed && str_contains($query, 'insert into "sync_changes"')) {
            $failed = true;
            throw new RuntimeException('simulated journal failure');
        }
    });
    $this->postJson($this->base.'/pull')->assertStatus(500);
    $this->assertDatabaseCount('sync_profiles', 0);
    $this->assertDatabaseCount('sync_streams', 0);
    $this->postJson($this->base.'/pull')->assertOk()->assertJsonPath('changes.0.sequence', 1);
});

it('reverses and reapplies the migration without altering existing profiles', function () {
    $migration = require database_path('migrations/2026_10_09_222450_create_sync_protocol_tables.php');
    $migration->down();
    expect(Schema::hasTable('sync_operations'))->toBeFalse()->and($this->profile->fresh()->revision)->toBe(1);
    $migration->up();
    $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)->assertOk();
});
