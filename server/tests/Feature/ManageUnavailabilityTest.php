<?php

use App\Models\OfficialAssignment;
use App\Models\OrganizationMembership;
use App\Services\Availability\ManageUnavailability;
use App\Services\Availability\SaveUnavailability;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ranger = OrganizationMembership::factory()->create(['role' => 'ranger']);
    $this->manager = OrganizationMembership::factory()->create(['organization_id' => $this->ranger->organization_id, 'role' => 'management']);
    $this->id = (string) Str::uuid();
    $this->base = "/api/v1/organizations/{$this->ranger->organization_id}";
    $this->url = $this->base.'/unavailability/'.$this->id;
    $this->input = ['membership_id' => $this->ranger->id, 'expected_revision' => null,
        'starts_at' => '2026-11-01T07:00:00Z', 'ends_at' => '2026-11-01T12:00:00Z', 'reason' => 'Emergency reported by phone'];
});

it('notifies Management and syncs revision-specific acknowledgment without repairing coverage', function () {
    $assignment = OfficialAssignment::create(['id' => (string) Str::uuid(), 'organization_id' => $this->ranger->organization_id,
        'organization_membership_id' => $this->ranger->id, 'revision' => 1,
        'starts_at' => '2026-11-01T10:00:00Z', 'ends_at' => '2026-11-01T18:00:00Z']);
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertSuccessful()
        ->assertJsonPath('data.acknowledged_at', null)->assertJsonPath('data.assignment_conflicts.0.affected_coverage.starts_at', '2026-11-01T10:00:00Z')
        ->assertJsonPath('data.assignment_conflicts.0.affected_coverage.ends_at', '2026-11-01T12:00:00Z');
    $this->get('/admin/main')->assertOk()->assertSee('1 need acknowledgment');
    $this->getJson($this->base.'/unavailability')->assertOk()->assertJsonCount(1, 'data');
    $page = $this->actingAs($this->ranger->user)->postJson($this->base.'/sync/pull')->assertOk()->json();
    $this->actingAs($this->manager->user)->postJson($this->url.'/acknowledge', ['expected_revision' => 1])->assertOk();
    DB::table('unavailability_acknowledgments')->update(['acknowledged_at' => '2026-10-01 12:00:00']);
    $at = DB::table('unavailability_acknowledgments')->value('acknowledged_at');
    $this->postJson($this->url.'/acknowledge', ['expected_revision' => 1])->assertOk();
    expect(DB::table('unavailability_acknowledgments')->value('acknowledged_at'))->toBe($at);
    $this->get('/admin/main')->assertSee('0 need acknowledgment');
    $this->actingAs($this->ranger->user, 'sanctum')->postJson($this->base.'/sync/pull', ['checkpoint' => $page['checkpoint']])
        ->assertOk()->assertJsonPath('changes.0.value.revision', 1)->assertJsonCount(1, 'changes.0.value.assignment_conflicts')
        ->assertJsonPath('changes.0.value.acknowledged_at', fn ($value) => is_string($value));
    expect($assignment->fresh()->revision)->toBe(1);
    $this->assertDatabaseCount('official_assignment_changes', 0);
    $this->assertDatabaseCount('unavailability_changes', 1);
    $this->assertDatabaseHas('unavailability_acknowledgments', ['actor_id' => $this->manager->user_id, 'revision' => 1]);
    $this->assertDatabaseHas('unavailability_changes', ['actor_id' => $this->manager->user_id, 'reason' => $this->input['reason']]);
});

it('renews notices on edits preserves audit and rejects stale acknowledgments and offline writes', function () {
    app(SaveUnavailability::class)->handle($this->ranger->user, $this->ranger, $this->id, null, $this->input);
    $this->actingAs($this->manager->user)->postJson($this->url.'/acknowledge', ['expected_revision' => 1])->assertOk();
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1, 'ends_at' => '2026-11-01T13:00:00Z'])
        ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.acknowledged_at', null);
    $this->postJson($this->url.'/acknowledge', ['expected_revision' => 1])->assertConflict();
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1])->assertConflict();
    $operation = ['operation_id' => (string) Str::uuid(), 'type' => 'unavailability.save', 'record_id' => $this->id,
        'expected_revision' => 1, 'payload' => collect($this->input)->only(['membership_id', 'starts_at', 'ends_at'])->all()];
    $response = $this->actingAs($this->ranger->user)->postJson($this->base.'/sync/push', $operation)
        ->assertOk()->assertJsonPath('status', 'conflict')->json();
    $this->postJson($this->base.'/sync/push', $operation)->assertExactJson($response);
    $this->postJson($this->base.'/sync/push', [...$operation, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 2])
        ->assertJsonPath('status', 'accepted')->assertJsonPath('unavailability.acknowledged_at', null);
    $this->assertDatabaseCount('unavailability_changes', 3);
    $audit = DB::table('unavailability_changes')->where('revision', 2)->first();
    expect(json_decode($audit->before, true)['ends_at'])->toContain('12:00:00');
    expect(json_decode($audit->after, true)['ends_at'])->toContain('13:00:00');
    $this->assertDatabaseCount('unavailability_acknowledgments', 1);
});

it('enforces authentication organization active roles and immutable Ranger ownership', function () {
    $this->putJson($this->url, $this->input)->assertUnauthorized();
    $this->actingAs($this->ranger->user)->putJson($this->url, $this->input)->assertForbidden();
    $this->getJson($this->base.'/unavailability')->assertForbidden();
    $this->postJson($this->url.'/acknowledge', ['expected_revision' => 1])->assertForbidden();
    $this->get('/admin/organizations/'.$this->ranger->organization_id.'/unavailability/new')->assertForbidden();
    $foreign = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->actingAs($foreign->user)->putJson($this->url, $this->input)->assertForbidden();
    $this->actingAs($this->manager->user)->putJson($this->url, [...$this->input, 'membership_id' => $foreign->id])->assertNotFound();
    $this->putJson($this->url, [...$this->input, 'membership_id' => $this->manager->id])->assertForbidden();
    $this->putJson($this->url, $this->input)->assertSuccessful();
    $peer = OrganizationMembership::factory()->create(['organization_id' => $this->ranger->organization_id]);
    $this->putJson($this->url, [...$this->input, 'membership_id' => $peer->id, 'expected_revision' => 1])->assertForbidden();
    $this->actingAs($peer->user)->postJson($this->base.'/sync/pull')->assertJsonCount(0, 'changes');
    $this->manager->update(['is_active' => false]);
    $this->actingAs($this->manager->user)->postJson($this->url.'/acknowledge', ['expected_revision' => 1])->assertForbidden();
    expect(fn () => app(ManageUnavailability::class)->save($this->manager->user, $this->ranger->organization, $this->id, $this->input))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('unavailability_changes', 1);
});

it('validates Management intent before any writes', function (array $change) {
    $this->actingAs($this->manager->user)->putJson($this->url, [...$this->input, ...$change])->assertUnprocessable();
    $this->assertDatabaseCount('unavailabilities', 0);
    $this->assertDatabaseCount('unavailability_changes', 0);
})->with([
    [['reason' => '']], [['ends_at' => '2026-11-01T07:00:00Z']], [['starts_at' => '2026-02-30T00:00:00Z']],
    [['expected_revision' => 0]], [['expected_revision' => 2147483647]],
]);

it('rolls back Management edits when audit storage fails', function () {
    DB::connection()->beforeExecuting(function ($query) {
        if (str_contains($query, 'insert into "unavailability_changes"')) {
            throw new RuntimeException('Audit unavailable');
        }
    });
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertStatus(500);
    $this->assertDatabaseCount('unavailabilities', 0);
});

it('shares validation and stale input preservation with Orchid', function () {
    $admin = '/admin/organizations/'.$this->ranger->organization_id.'/unavailability/new';
    $this->actingAs($this->manager->user)->get($admin)->assertOk()->assertSee('Acknowledgment means seen');
    $this->post($admin.'/save', ['entry' => [...$this->input, 'id' => $this->id]])->assertRedirect();
    $this->assertDatabaseHas('unavailabilities', ['id' => $this->id, 'revision' => 1]);
    $admin = str_replace('/new', '/'.$this->id, $admin);
    $this->from($admin)->post($admin.'/save', ['entry' => [...$this->input, 'expected_revision' => null]])
        ->assertSessionHasErrors('entry')->assertSessionHasInput('entry.reason', $this->input['reason']);
    $this->post($admin.'/acknowledge', ['notice_id' => $this->id, 'notice_revision' => 1])->assertRedirect();
    $this->assertDatabaseCount('unavailability_acknowledgments', 1);
});
