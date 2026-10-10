<?php

use App\Enums\OrganizationRole;
use App\Models\OfficialAssignment;
use App\Models\OrganizationMembership;
use App\Services\Scheduling\SaveOfficialAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ranger = OrganizationMembership::factory()->create(['role' => OrganizationRole::Ranger]);
    $this->manager = OrganizationMembership::factory()->create([
        'organization_id' => $this->ranger->organization_id, 'role' => OrganizationRole::Management,
    ]);
    $this->base = "/api/v1/organizations/{$this->ranger->organization_id}";
    $this->assignmentId = (string) Str::uuid();
    $this->assignment = ['membership_id' => $this->ranger->id, 'expected_revision' => null,
        'starts_at' => '2026-11-01T10:00:00Z', 'ends_at' => '2026-11-01T18:00:00Z', 'reason' => 'Offline proof'];
    $this->report = ['operation_id' => (string) Str::uuid(), 'type' => 'unavailability.save',
        'record_id' => (string) Str::uuid(), 'expected_revision' => null,
        'payload' => ['membership_id' => $this->ranger->id, 'starts_at' => '2026-11-01T07:00:00Z', 'ends_at' => '2026-11-01T12:00:00Z']];
});

it('preserves offline intent and flags a manager-changed official interval without changing its revision', function () {
    $url = $this->base.'/official-assignments/'.$this->assignmentId;
    $this->actingAs($this->manager->user)->putJson($url, [...$this->assignment, 'starts_at' => '2026-11-01T14:00:00Z'])
        ->assertOk()->assertJsonPath('revision', 1);
    $this->putJson($url, [...$this->assignment, 'expected_revision' => 1])->assertOk()->assertJsonPath('revision', 2);
    $receipt = $this->actingAs($this->ranger->user)->postJson($this->base.'/sync/push', $this->report)
        ->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('unavailability.assignment_conflicts.0.id', $this->assignmentId)
        ->assertJsonPath('unavailability.assignment_conflicts.0.revision', 2)->json();
    $this->postJson($this->base.'/sync/push', $this->report)->assertExactJson($receipt);
    $page = $this->postJson($this->base.'/sync/pull')->assertOk()->assertJsonPath('changes.0.value.assignment_conflicts.0.revision', 2)->json();
    expect(OfficialAssignment::findOrFail($this->assignmentId)->revision)->toBe(2);
    $this->assertDatabaseCount('official_assignment_changes', 2);
    $this->assertDatabaseCount('unavailability_changes', 1);
    $this->assertDatabaseHas('official_assignment_changes', ['actor_id' => $this->manager->user_id, 'revision' => 2, 'reason' => 'Offline proof']);

    // Conflict projection changes even though the unavailability's own revision does not.
    $this->actingAs($this->manager->user)->putJson($url, [...$this->assignment, 'expected_revision' => 2, 'starts_at' => '2026-11-01T12:00:00Z'])->assertOk();
    $this->actingAs($this->ranger->user)->postJson($this->base.'/sync/pull', ['checkpoint' => $page['checkpoint']])
        ->assertOk()->assertJsonPath('changes.0.value.revision', 1)->assertJsonPath('changes.0.value.assignment_conflicts', []);
});

it('detects conflicts introduced after a report was accepted and does not leak peer reports', function () {
    $this->actingAs($this->ranger->user)->postJson($this->base.'/sync/push', $this->report)->assertOk();
    $page = $this->postJson($this->base.'/sync/pull')->assertOk()->json();
    $this->actingAs($this->manager->user)->putJson($this->base.'/official-assignments/'.$this->assignmentId, $this->assignment)->assertOk();
    $this->actingAs($this->ranger->user)->postJson($this->base.'/sync/pull', ['checkpoint' => $page['checkpoint']])
        ->assertOk()->assertJsonCount(1, 'changes.0.value.assignment_conflicts');
    $peer = OrganizationMembership::factory()->create(['organization_id' => $this->ranger->organization_id]);
    $this->actingAs($peer->user)->postJson($this->base.'/sync/pull')->assertOk()->assertJsonCount(0, 'changes');
});

it('requires current management authorization and valid same-organization Ranger targets', function () {
    $url = $this->base.'/official-assignments/'.$this->assignmentId;
    $this->putJson($url, $this->assignment)->assertUnauthorized();
    $this->actingAs($this->ranger->user)->putJson($url, $this->assignment)->assertForbidden();
    $foreign = OrganizationMembership::factory()->create();
    $this->actingAs($this->manager->user)->putJson($url, [...$this->assignment, 'membership_id' => $foreign->id])->assertForbidden();
    $this->putJson($url, [...$this->assignment, 'ends_at' => $this->assignment['starts_at']])->assertUnprocessable();
    $this->putJson($url, [...$this->assignment, 'reason' => ''])->assertUnprocessable();
    $this->putJson($url, $this->assignment)->assertOk();
    $this->putJson($url, $this->assignment)->assertConflict();
    $this->manager->update(['role' => OrganizationRole::Ranger]);
    $this->putJson($url, [...$this->assignment, 'expected_revision' => 1])->assertForbidden();
    $this->assertDatabaseCount('official_assignment_changes', 1);
});

it('refuses a cached Ranger workspace after role loss before pulling management data', function () {
    $this->ranger->update(['role' => OrganizationRole::Management]);
    $this->actingAs($this->ranger->user)->withHeader('X-RUSH-Membership', $this->ranger->id)
        ->postJson($this->base.'/sync/pull')->assertForbidden();
    $this->assertDatabaseCount('sync_streams', 0);
});

it('rolls back the assignment when its audit cannot be persisted', function () {
    DB::connection()->beforeExecuting(function ($query) {
        if (str_contains($query, 'insert into "official_assignment_changes"')) {
            throw new RuntimeException('Audit unavailable');
        }
    });
    $this->actingAs($this->manager->user)->putJson($this->base.'/official-assignments/'.$this->assignmentId, $this->assignment)->assertStatus(500);
    $this->assertDatabaseCount('official_assignments', 0);
});

it('rechecks management access in the shared service', function () {
    expect(fn () => app(SaveOfficialAssignment::class)->handle($this->ranger->user,
        $this->ranger->organization, $this->assignmentId, $this->assignment))
        ->toThrow(HttpException::class);
});

it('rejects both sync directions if a tab uses a different signed-in account', function () {
    $this->actingAs($this->manager->user)->withHeader('X-RUSH-Account', (string) $this->ranger->user_id);
    $this->postJson($this->base.'/sync/pull')->assertForbidden();
    $this->postJson($this->base.'/sync/push', $this->report)->assertForbidden();
    $this->assertDatabaseCount('sync_streams', 0);
    $this->assertDatabaseCount('unavailabilities', 0);
});
