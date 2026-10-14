<?php

use App\Models\OrganizationMembership;
use App\Models\Unavailability;
use App\Services\Events\SaveEvent;
use App\Services\Seasons\ConfigurationYear;
use App\Services\Seasons\SaveSeason;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-13T12:00:00Z'));
    $this->manager = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->manager->organization->update(['timezone' => 'America/Los_Angeles']);
    $this->ranger = OrganizationMembership::factory()->create(['organization_id' => $this->manager->organization_id]);
    $this->input = ['name' => 'Winter archive', 'starts_on' => '2025-12-01', 'ends_on' => '2026-02-28',
        'timezone' => 'America/Los_Angeles', 'week_starts_on' => 1, 'expected_revision' => null,
        'phases' => [['name' => 'Winter phase', 'starts_on' => '2025-12-01', 'ends_on' => '2026-02-28']]];
    $this->archive = app(SaveSeason::class)->handle($this->manager->user, $this->manager->organization, (string) Str::uuid(), $this->input);
    $this->current = app(SaveSeason::class)->handle($this->manager->user, $this->manager->organization, (string) Str::uuid(),
        [...$this->input, 'name' => 'Current season', 'starts_on' => '2026-03-01', 'ends_on' => '2026-11-30', 'phases' => []]);
    $this->eventInput = ['expected_revision' => null, 'expected_season_revision' => 1, 'name' => 'Archived meeting',
        'starts_local' => '2026-01-12 18:00:00', 'ends_local' => '2026-01-12 19:00:00', 'paid' => true,
        'target_type' => 'none', 'target_value' => null, 'counts_scheduled' => false,
        'attendance_membership_ids' => [$this->ranger->id], 'reason' => 'Initial meeting'];
    $this->event = app(SaveEvent::class)->handle($this->manager->user, $this->manager->organization,
        $this->archive->id, (string) Str::uuid(), $this->eventInput);
    $this->notice = Unavailability::create(['organization_id' => $this->manager->organization_id,
        'organization_membership_id' => $this->ranger->id, 'starts_at' => '2026-01-01T07:00:00Z', 'ends_at' => '2026-01-01T10:00:00Z', 'revision' => 1]);
    $this->base = '/api/v1/organizations/'.$this->manager->organization_id;
    $this->admin = '/admin/organizations/'.$this->manager->organization_id;
    $this->travelTo(CarbonImmutable::parse('2026-10-13T12:00:00Z'));
    $this->actingAs($this->manager->user);
});

it('scopes menus and lists by starting year and keeps selection in links rather than shared session state', function () {
    $this->get('/admin/main')->assertOk()->assertSee('Current season')->assertDontSee('Winter archive');
    $this->get('/admin/main?year=2025')->assertOk()->assertSee('Winter archive')->assertDontSee('Current season')
        ->assertSee('Read-only archive')->assertDontSee('Create season —');
    $this->get($this->admin.'/seasons/new?year=2025')->assertOk()->assertSee('Winter archive')
        ->assertDontSee('Current season')->assertDontSee('Save season and phases')->assertDontSee('Create a new season');
    $this->get($this->admin.'/seasons/'.$this->archive->id.'?year=2026')->assertOk()
        ->assertSee('Read-only archive')->assertSee('management-archive')->assertDontSee('Save season and phases');
    $this->get('/admin/main')->assertOk()->assertSee('Current season')->assertDontSee('Winter archive');
    $this->get('/admin/main?year=2027')->assertOk()->assertDontSee('Read-only archive')->assertSee('Create a season to configure events');
    $this->getJson('/admin/main?year[]=2025')->assertUnprocessable();
});

it('summarizes only the selected year in the management overview', function () {
    $response = $this->get('/admin/main?year=2025')->assertOk()->assertSee('2025 overview')
        ->assertSee('historical notices remain unacknowledged.');
    foreach (['Seasons', 'Phases', 'One-off events', 'Unacknowledged notices'] as $label) {
        expect($response->getContent())->toMatch('/'.preg_quote($label, '/').'<\/dt>\s*<dd[^>]*>1<\/dd>/');
    }
    $response = $this->get('/admin/main?year=2026')->assertOk()->assertSee('2026 overview')
        ->assertSee('No unacknowledged notices in this year.');
    foreach (['Phases', 'One-off events', 'Unacknowledged notices'] as $label) {
        expect($response->getContent())->toMatch('/'.preg_quote($label, '/').'<\/dt>\s*<dd[^>]*>0<\/dd>/');
    }
});

it('keeps archived event and phase screens viewable without mutation controls', function () {
    $phase = $this->archive->phases->first()->id;
    foreach (['coverage' => 'Save phase coverage', 'policies' => 'Save scheduling policies'] as $path => $button) {
        $this->get($this->admin.'/phases/'.$phase.'/'.$path)->assertOk()->assertSee('Read-only archive')->assertDontSee($button);
    }
    $this->get($this->admin.'/seasons/'.$this->archive->id.'/events/'.$this->event->id)->assertOk()
        ->assertSee('Archived meeting')->assertSee('Read-only archive')->assertDontSee('Save event and attendance')->assertDontSee('Create a one-off event');
});

it('rejects archived edits through APIs and forged admin posts without changing revisions or audit', function () {
    $seasonUrl = $this->base.'/seasons/'.$this->archive->id;
    $this->putJson($seasonUrl, [...$this->input, 'expected_revision' => 1, 'reason' => 'Move to current year', 'starts_on' => '2026-01-01'])->assertForbidden();
    $eventUrl = $seasonUrl.'/events/'.$this->event->id;
    $this->putJson($eventUrl, [...$this->eventInput, 'expected_revision' => 1])->assertForbidden();
    $this->putJson($seasonUrl.'/events/'.Str::uuid(), $this->eventInput)->assertForbidden();
    foreach (['coverage', 'policies'] as $path) {
        $this->putJson($this->base.'/phases/'.$this->archive->phases->first()->id.'/'.$path, [])->assertForbidden();
    }
    $this->post(str_replace('/api/v1', '/admin', $eventUrl).'/save?year=2026', ['configuration' => $this->eventInput])->assertForbidden();
    $this->post($this->admin.'/seasons/new/save?year=2025', ['configuration' => $this->input])->assertForbidden();
    expect($this->archive->fresh()->revision)->toBe(1)->and($this->event->fresh()->revision)->toBe(1);
    $this->assertDatabaseCount('season_changes', 2);
    $this->assertDatabaseCount('event_changes', 1);
    $this->assertDatabaseCount('event_attendances', 1);
});

it('prevents backdating current configuration while allowing future seasons', function () {
    $this->putJson($this->base.'/seasons/'.Str::uuid(), $this->input)->assertForbidden();
    $this->putJson($this->base.'/seasons/'.$this->current->id,
        [...$this->input, 'expected_revision' => 1, 'reason' => 'Backdate'])->assertForbidden();
    $this->putJson($this->base.'/seasons/'.Str::uuid(), [...$this->input, 'name' => 'Future season',
        'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'phases' => []])->assertCreated();
    $this->get('/admin/main?year=2027')->assertSee('Future season')->assertDontSee('Current season');
});

it('filters notices by their local starting year and blocks management edits and acknowledgments', function () {
    $this->get($this->admin.'/unavailability/new?year=2025')->assertOk()->assertSee('Read-only archive')->assertSee($this->notice->id)
        ->assertDontSee('Save on behalf of Ranger')->assertDontSee('Acknowledge as seen')->assertDontSee('Edit range');
    $this->get($this->admin.'/unavailability/new?year=2026')->assertOk()->assertDontSee($this->notice->id);
    $input = ['membership_id' => $this->ranger->id, 'expected_revision' => 1,
        'starts_at' => '2026-10-01T10:00:00Z', 'ends_at' => '2026-10-01T11:00:00Z', 'reason' => 'Move history'];
    $this->putJson($this->base.'/unavailability/'.$this->notice->id, $input)->assertForbidden();
    $this->putJson($this->base.'/unavailability/'.Str::uuid(), [...$input, 'expected_revision' => null,
        'starts_at' => '2026-01-01T07:00:00Z', 'ends_at' => '2026-01-01T10:00:00Z'])->assertForbidden();
    $this->postJson($this->base.'/unavailability/'.$this->notice->id.'/acknowledge', ['expected_revision' => 1])->assertForbidden();
    $this->assertDatabaseCount('unavailability_acknowledgments', 0);
    expect($this->notice->fresh()->revision)->toBe(1);
});

it('locks a cross-year season at midnight in its season time zone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T07:59:59Z'));
    expect(app(ConfigurationYear::class)->seasonIsPast($this->archive))->toBeFalse();
    $this->travelTo(CarbonImmutable::parse('2026-01-01T08:00:00Z'));
    expect(app(ConfigurationYear::class)->seasonIsPast($this->archive))->toBeTrue();
});

it('offers historical notice years even when no season was configured for that year', function () {
    Unavailability::create(['organization_id' => $this->manager->organization_id,
        'organization_membership_id' => $this->ranger->id, 'starts_at' => '2021-01-01T07:00:00Z', 'ends_at' => '2021-01-01T10:00:00Z', 'revision' => 1]);
    $this->get('/admin/main')->assertOk()->assertSee('/admin/main?year=2020', false)->assertDontSee('/admin/main?year=2021', false);
});

it('does not expose other organizations through the selector or archived links', function () {
    $other = OrganizationMembership::factory()->create(['role' => 'management']);
    $this->actingAs($other->user)->get('/admin/main?year=2025')->assertOk()->assertDontSee('Winter archive')->assertDontSee('Current season');
    $this->get($this->admin.'/seasons/'.$this->archive->id)->assertForbidden();
    $this->actingAs($this->ranger->user)->get('/admin/main?year=2025')->assertForbidden();
});
