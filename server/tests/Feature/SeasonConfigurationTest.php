<?php

use App\Models\OrganizationMembership;
use App\Models\Season;
use App\Services\Seasons\SaveSeason;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->id = (string) Str::uuid();
    $this->base = "/api/v1/organizations/{$this->manager->organization_id}/seasons";
    $this->url = $this->base.'/'.$this->id;
    $this->input = [
        'name' => '2026 Ranger season', 'starts_on' => '2026-03-01', 'ends_on' => '2026-11-30',
        'timezone' => 'America/Los_Angeles', 'week_starts_on' => 1, 'expected_revision' => null, 'reason' => 'Initial season',
        'phases' => [
            ['id' => (string) Str::uuid(), 'name' => 'Low staffing', 'starts_on' => '2026-03-01', 'ends_on' => '2026-05-31'],
            ['id' => (string) Str::uuid(), 'name' => 'Full staffing', 'starts_on' => '2026-06-01', 'ends_on' => '2026-11-30'],
        ],
    ];
});

it('creates and atomically edits a season and its phase boundary with versioned audit', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertCreated()
        ->assertJsonPath('revision', 1)->assertJsonPath('timezone', 'America/Los_Angeles')->assertJsonCount(2, 'phases');
    $updated = [...$this->input, 'expected_revision' => 1, 'week_starts_on' => 7, 'reason' => 'Adjust phase transition'];
    $updated['phases'][0]['ends_on'] = '2026-06-14';
    $updated['phases'][1]['starts_on'] = '2026-06-15';
    $this->putJson($this->url, $updated)->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('week_starts_on', 7);
    $this->getJson($this->url)->assertOk()->assertJsonPath('phases.1.starts_on', '2026-06-15');
    $this->getJson($this->base)->assertOk()->assertJsonCount(1, 'data');
    $this->putJson($this->url, $updated)->assertConflict()->assertJsonPath('code', 'revision_conflict');
    $this->assertDatabaseCount('seasons', 1);
    $this->assertDatabaseCount('phases', 2);
    $this->assertDatabaseCount('season_changes', 2);
    $audit = DB::table('season_changes')->where('revision', 2)->first();
    expect($audit->actor_id)->toBe($this->manager->user_id)
        ->and(json_decode($audit->before, true)['phases'][0]['ends_on'])->toBe('2026-05-31')
        ->and(json_decode($audit->after, true)['phases'][0]['ends_on'])->toBe('2026-06-14');
});

it('rejects invalid calendar inputs without partial persistence', function (string $field, mixed $value) {
    data_set($this->input, $field, $value);
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertUnprocessable();
    $this->assertDatabaseCount('seasons', 0);
    $this->assertDatabaseCount('phases', 0);
    $this->assertDatabaseCount('season_changes', 0);
})->with([
    ['timezone', 'PST'], ['timezone', '+08:00'], ['week_starts_on', 0], ['week_starts_on', 8],
    ['starts_on', '2026-02-30'], ['ends_on', '2026-01-01'],
    ['phases.0.starts_on', '2026-02-28'], ['phases.1.ends_on', '2026-12-01'],
    ['phases.1.starts_on', '2026-05-31'], ['phases.0.ends_on', '2026-02-28'],
    ['phases.0.name', ''], ['reason', ''],
]);

it('allows empty seasons and explicit unconfigured gaps but never silently removes saved phases', function () {
    $this->actingAs($this->manager->user)->putJson($this->url, [...$this->input, 'phases' => []])->assertCreated();
    $this->input['phases'][0]['ends_on'] = '2026-05-01';
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1])->assertOk();
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 2, 'phases' => []])->assertUnprocessable();
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 2, 'ends_on' => '2026-06-30'])->assertUnprocessable();
    $this->assertDatabaseCount('season_changes', 2);
});

it('generates phase identifiers and refuses duplicate or foreign phase identities', function () {
    $this->input['phases'][0]['id'] = null;
    $this->input['phases'][1]['id'] = null;
    $data = $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertCreated()->json();
    expect(Str::isUuid($data['phases'][0]['id']))->toBeTrue();
    $this->input['phases'] = $data['phases'];
    $this->putJson($this->base.'/'.Str::uuid(), $this->input)->assertUnprocessable();
    $this->input['phases'][1]['id'] = $this->input['phases'][0]['id'];
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1])->assertUnprocessable();
});

it('enforces current management membership and organization scoping in API and service', function () {
    $this->getJson($this->base)->assertUnauthorized();
    $this->putJson($this->url, $this->input)->assertUnauthorized();
    $ranger = OrganizationMembership::factory()->create(['organization_id' => $this->manager->organization_id]);
    $this->actingAs($ranger->user)->getJson($this->base)->assertForbidden();
    $this->putJson($this->url, $this->input)->assertForbidden();
    expect(fn () => app(SaveSeason::class)->handle($ranger->user, $this->manager->organization, $this->id, $this->input))
        ->toThrow(AuthorizationException::class);
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertCreated();
    $other = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->actingAs($other->user)->getJson($this->url)->assertForbidden();
    $forged = "/api/v1/organizations/{$other->organization_id}/seasons/{$this->id}";
    $this->getJson($forged)->assertNotFound();
    $this->putJson($forged, $this->input)->assertNotFound();
    $this->actingAs($this->manager->user);
    $this->manager->update(['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
    $this->putJson($this->url, [...$this->input, 'expected_revision' => 1])->assertForbidden();
});

it('rolls back season and phases if audit persistence fails', function () {
    DB::connection()->beforeExecuting(function ($query) {
        if (str_contains($query, 'insert into "season_changes"')) {
            throw new RuntimeException('Audit unavailable');
        }
    });
    $this->actingAs($this->manager->user)->putJson($this->url, $this->input)->assertStatus(500);
    $this->assertDatabaseCount('seasons', 0);
    $this->assertDatabaseCount('phases', 0);
});

it('offers scoped Orchid editing and preserves stale form input without opening generic admin tools', function () {
    $route = "/admin/organizations/{$this->manager->organization_id}/seasons/new";
    $this->actingAs($this->manager->user)->get('/admin/main')->assertOk()->assertSee('Seasons and phases');
    $this->get($route)->assertOk()->assertSee('Season time zone')->assertSee('Week starts on');
    $this->post($route.'/save', ['configuration' => [...$this->input, 'id' => $this->id]])->assertRedirect();
    $edit = "/admin/organizations/{$this->manager->organization_id}/seasons/{$this->id}";
    $this->get($edit)->assertOk()->assertSee('Low staffing');
    $this->from($edit)->post($edit.'/save', ['configuration' => [...$this->input, 'expected_revision' => null]])
        ->assertRedirect($edit)->assertSessionHasErrors('configuration')->assertSessionHasInput('configuration.name', '2026 Ranger season');
    $this->post($edit.'/query')->assertStatus(405);
    $foreign = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->actingAs($foreign->user)->get($edit)->assertForbidden();
    $this->post($edit.'/save', ['configuration' => $this->input])->assertForbidden();
    $this->manager->update(['role' => 'ranger']);
    $this->actingAs($this->manager->user)->get($edit)->assertForbidden();
    expect(Season::findOrFail($this->id)->revision)->toBe(1);
});
