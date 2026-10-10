<?php

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\Unavailability;
use App\Services\Availability\SaveUnavailability;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->membership = OrganizationMembership::factory()->create(['role' => OrganizationRole::Ranger]);
    $this->actor = $this->membership->user;
    $this->base = "/api/v1/organizations/{$this->membership->organization_id}/sync";
    $this->operation = [
        'operation_id' => (string) Str::uuid(), 'type' => 'unavailability.save',
        'record_id' => (string) Str::uuid(), 'expected_revision' => null,
        'payload' => ['membership_id' => $this->membership->id,
            'starts_at' => '2026-11-01T07:00:00Z', 'ends_at' => '2026-11-01T12:00:00Z'],
    ];
});

it('accepts overnight UTC unavailability once and pulls only the owner record', function () {
    $result = $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)
        ->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('unavailability.revision', 1)
        ->assertJsonPath('unavailability.starts_at', '2026-11-01T07:00:00Z')->json();
    $this->postJson($this->base.'/push', $this->operation)->assertExactJson($result);
    $this->assertDatabaseCount('unavailabilities', 1);
    $this->assertDatabaseCount('unavailability_changes', 1);
    $this->assertDatabaseCount('sync_operations', 1);
    $this->assertDatabaseHas('unavailability_changes', ['actor_id' => $this->actor->id, 'revision' => 1]);
    $this->postJson($this->base.'/pull')->assertOk()->assertJsonPath('changes.0.record_type', 'unavailability')
        ->assertJsonPath('changes.0.value', $result['unavailability']);

    $peer = OrganizationMembership::factory()->create(['organization_id' => $this->membership->organization_id]);
    $this->actingAs($peer->user)->postJson($this->base.'/pull')->assertOk()->assertJsonCount(0, 'changes');
});

it('edits with a current revision and preserves the accepted range on stale replay', function () {
    $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)->assertOk();
    $update = [...$this->operation, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 1,
        'payload' => [...$this->operation['payload'], 'ends_at' => '2026-11-01T13:00:00Z']];
    $this->postJson($this->base.'/push', $update)->assertJsonPath('unavailability.revision', 2);
    $stale = [...$update, 'operation_id' => (string) Str::uuid()];
    $conflict = $this->postJson($this->base.'/push', $stale)->assertOk()
        ->assertJsonPath('status', 'conflict')->assertJsonPath('error.code', 'revision_conflict')->json();
    $this->postJson($this->base.'/push', $stale)->assertExactJson($conflict);
    $this->assertDatabaseCount('unavailability_changes', 2);
    expect(Unavailability::findOrFail($update['record_id'])->ends_at->format('H:i'))->toBe('13:00');
    $this->postJson($this->base.'/push', [...$update, 'payload' => $this->operation['payload']])
        ->assertConflict()->assertJsonPath('code', 'operation_id_reused');
});

it('rejects invalid intervals durably without writing availability or audit', function (array $payload) {
    $input = [...$this->operation, 'payload' => [...$this->operation['payload'], ...$payload]];
    $result = $this->actingAs($this->actor)->postJson($this->base.'/push', $input)->assertOk()
        ->assertJsonPath('status', 'rejected')->assertJsonPath('error.code', 'validation_failed')->json();
    $this->postJson($this->base.'/push', $input)->assertExactJson($result);
    $this->assertDatabaseCount('unavailabilities', 0);
    $this->assertDatabaseCount('unavailability_changes', 0);
})->with([
    [['starts_at' => null]], [['ends_at' => '2026-11-01T07:00:00Z']],
    [['ends_at' => '2026-10-31T07:00:00Z']], [['starts_at' => '2026-02-30T07:00:00Z']],
    [['starts_at' => '2026-11-01 07:00']], [['ends_at' => '2026-11-01T12:00:00-08:00']],
]);

it('enforces authentication current Ranger ownership organization and role on every push', function () {
    $this->postJson($this->base.'/push', $this->operation)->assertUnauthorized();
    $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)->assertOk();
    $record = Unavailability::findOrFail($this->operation['record_id']);
    expect(Gate::forUser($this->actor)->allows('view', $record))->toBeTrue();
    $peer = OrganizationMembership::factory()->create(['organization_id' => $this->membership->organization_id]);
    expect(Gate::forUser($peer->user)->allows('view', $record))->toBeFalse();
    $this->actingAs($peer->user)->postJson($this->base.'/push', $this->operation)->assertForbidden();
    $this->postJson($this->base.'/push', [...$this->operation, 'operation_id' => (string) Str::uuid(),
        'payload' => [...$this->operation['payload'], 'membership_id' => $peer->id]])->assertForbidden();
    $foreign = OrganizationMembership::factory()->create();
    $this->actingAs($foreign->user)->postJson($this->base.'/push', $this->operation)->assertForbidden();
    $this->membership->update(['role' => OrganizationRole::Management]);
    expect(Gate::forUser($this->actor)->allows('update', $record))->toBeFalse();
    $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)->assertForbidden();
    $this->membership->update(['role' => OrganizationRole::Ranger, 'is_active' => false]);
    $this->postJson($this->base.'/push', $this->operation)->assertForbidden();
    $this->assertDatabaseCount('unavailability_changes', 1);
});

it('rolls back a domain write and audit when storing its receipt fails', function () {
    $failed = false;
    DB::connection()->beforeExecuting(function ($query) use (&$failed) {
        if (! $failed && str_contains($query, 'insert into "sync_operations"')) {
            $failed = true;
            throw new RuntimeException('Receipt storage failed');
        }
    });
    $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)->assertStatus(500);
    $this->assertDatabaseCount('unavailabilities', 0);
    $this->assertDatabaseCount('unavailability_changes', 0);
    $this->postJson($this->base.'/push', $this->operation)->assertJsonPath('status', 'accepted');
});

it('keeps the domain service authorized outside the sync controller', function () {
    $peer = OrganizationMembership::factory()->create();
    expect(fn () => app(SaveUnavailability::class)->handle($peer->user, $this->membership,
        $this->operation['record_id'], null, $this->operation['payload']))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('unavailabilities', 0);
});

it('paginates availability changes and redacts historical records after role loss', function () {
    $this->actingAs($this->actor)->postJson($this->base.'/push', $this->operation)->assertOk();
    $second = [...$this->operation, 'operation_id' => (string) Str::uuid(), 'record_id' => (string) Str::uuid()];
    $this->postJson($this->base.'/push', $second)->assertOk();
    $page = $this->postJson($this->base.'/pull', ['limit' => 1])->assertJsonPath('has_more', true)->json();
    $this->postJson($this->base.'/pull', ['checkpoint' => $page['checkpoint'], 'limit' => 1])
        ->assertJsonCount(1, 'changes')->assertJsonPath('has_more', false);
    $this->membership->update(['role' => OrganizationRole::Management]);
    $this->postJson($this->base.'/pull', ['checkpoint' => $page['checkpoint']])->assertConflict();
    $this->postJson($this->base.'/pull')->assertJsonCount(0, 'changes');
});
