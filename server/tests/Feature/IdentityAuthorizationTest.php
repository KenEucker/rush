<?php

use App\Models\MemberProfile;
use App\Models\OrganizationMembership;
use App\Services\Identity\UpdateMemberProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('permits own profile reads and validated writes without mass assignment of privileges', function () {
    $profile = MemberProfile::factory()->create();
    $user = $profile->membership->user;
    $url = "/api/v1/organizations/{$profile->organization_id}/profiles/{$profile->id}";
    $this->getJson($url)->assertUnauthorized();
    $this->patchJson($url, ['display_name' => 'Guest'])->assertUnauthorized();
    $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('id', $profile->id);
    $this->patchJson($url, ['expected_revision' => 1, 'display_name' => 'Updated', 'phone' => '555-0100', 'role' => 'management', 'organization_id' => 'forged'])
        ->assertOk()->assertJsonPath('id', $profile->id)->assertJsonPath('display_name', 'Updated')
        ->assertJsonPath('phone', '555-0100')->assertJsonPath('revision', 2);
    expect($profile->fresh()->organization_id)->toBe($profile->organization_id)
        ->and($profile->membership->fresh()->role->value)->toBe('ranger');
    $this->patchJson($url, ['display_name' => '', 'phone' => str_repeat('x', 51)])
        ->assertUnprocessable()->assertJsonValidationErrors(['display_name', 'phone']);
});

it('denies peer and cross-organization reads and writes', function () {
    $own = MemberProfile::factory()->create();
    $peerMembership = OrganizationMembership::factory()->create(['organization_id' => $own->organization_id]);
    $peer = MemberProfile::factory()->for($peerMembership, 'membership')->create();
    $other = MemberProfile::factory()->create();
    $this->actingAs($own->membership->user);
    foreach ([$peer, $other] as $target) {
        $url = "/api/v1/organizations/{$target->organization_id}/profiles/{$target->id}";
        $this->getJson($url)->assertForbidden();
        $this->patchJson($url, ['display_name' => 'Forbidden'])->assertForbidden();
        expect($target->fresh()->display_name)->not->toBe('Forbidden');
    }
    $forged = "/api/v1/organizations/{$own->organization_id}/profiles/{$other->id}";
    $this->getJson($forged)->assertNotFound();
    $this->patchJson($forged, ['display_name' => 'Forbidden'])->assertNotFound();
});

it('limits Management profile reads to its organizations and retains owner-only profile writes', function () {
    $profile = MemberProfile::factory()->create();
    $manager = OrganizationMembership::factory()->create(['organization_id' => $profile->organization_id, 'role' => 'management']);
    $other = MemberProfile::factory()->create();
    $this->actingAs($manager->user);
    $url = "/api/v1/organizations/{$profile->organization_id}/profiles/{$profile->id}";
    $this->getJson($url)->assertOk();
    $this->patchJson($url, ['display_name' => 'Forbidden'])->assertForbidden();
    $this->getJson("/api/v1/organizations/{$other->organization_id}/profiles/{$other->id}")->assertForbidden();
});

it('rechecks active membership after revocation even with an existing session and loaded relations', function () {
    $profile = MemberProfile::factory()->create();
    $user = $profile->membership->user;
    $user->load('organizationMemberships');
    $this->actingAs($user);
    $profile->membership->update(['is_active' => false]);
    $url = "/api/v1/organizations/{$profile->organization_id}/profiles/{$profile->id}";
    $this->getJson($url)->assertForbidden();
    $this->patchJson($url, ['display_name' => 'Forbidden'])->assertForbidden();
    $this->getJson('/api/v1/session')->assertOk()->assertJsonCount(0, 'memberships');
});

it('enforces the same policy when the profile service is invoked outside an API controller', function () {
    $profile = MemberProfile::factory()->create();
    $other = OrganizationMembership::factory()->create()->user;
    expect(fn () => app(UpdateMemberProfile::class)->handle($other, $profile, ['display_name' => 'Forbidden']))
        ->toThrow(AuthorizationException::class);
});

it('protects Orchid using current membership and disables unscoped starter tools', function () {
    $this->get('/admin')->assertRedirect('/sign-in');
    $member = OrganizationMembership::factory()->create();
    $user = $member->user;
    $user->update(['permissions' => ['platform.index' => 1, 'platform.systems.users' => 1]]);
    $this->actingAs($user)->get('/admin')->assertForbidden();
    $member->update(['role' => 'management']);
    $this->get('/admin/main')->assertOk()->assertSee('RUSH Management')
        ->assertSee('formaction="'.route('platform.logout').'"', false);
    foreach (['/admin/users', '/admin/roles', '/admin/profile', '/admin/search/Casey'] as $url) {
        $this->get($url)->assertNotFound();
    }
    foreach (['/admin/systems/relation', '/admin/async'] as $url) {
        $this->post($url)->assertNotFound();
    }
    $this->post('/admin/systems/files')->assertForbidden();
    $member->update(['is_active' => false]);
    $this->get('/admin/main')->assertForbidden();
    $this->post('/admin/logout')->assertRedirect('/sign-in');
    $this->assertGuest();
});
