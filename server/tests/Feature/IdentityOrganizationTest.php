<?php

use App\Enums\OrganizationRole;
use App\Models\MemberProfile;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('models memberships and profiles inside an organization boundary', function () {
    $organization = Organization::factory()->create([
        'name' => 'Black Rock Rangers',
        'slug' => 'black-rock-rangers',
    ]);
    $user = User::factory()->create(['name' => 'Casey Brooks']);

    $membership = OrganizationMembership::factory()
        ->for($organization)
        ->for($user)
        ->create(['role' => OrganizationRole::Ranger]);

    $profile = MemberProfile::query()->create([
        'organization_id' => $organization->id,
        'organization_membership_id' => $membership->id,
        'display_name' => 'Casey',
        'phone' => '555-0100',
    ]);

    expect($membership->organization->is($organization))->toBeTrue()
        ->and($membership->user->is($user))->toBeTrue()
        ->and($membership->profile->is($profile))->toBeTrue()
        ->and($profile->organization->is($organization))->toBeTrue()
        ->and($profile->membership->is($membership))->toBeTrue()
        ->and($user->organizationMemberships()->first()->is($membership))->toBeTrue()
        ->and($user->memberProfiles()->first()->is($profile))->toBeTrue();
});

it('seeds a 5 to 15 person Ranger fixture with both V1 roles', function () {
    $this->seed(DatabaseSeeder::class);

    $organization = Organization::query()->where('slug', 'rush-demo-rangers')->sole();
    $memberships = $organization->memberships()->with('profile')->get();

    expect($memberships)->toHaveCount(10)
        ->and($memberships->count())->toBeBetween(5, 15)
        ->and($memberships->where('role', OrganizationRole::Ranger))->toHaveCount(8)
        ->and($memberships->where('role', OrganizationRole::Management))->toHaveCount(2)
        ->and($memberships->every(fn (OrganizationMembership $membership) => $membership->profile !== null))->toBeTrue()
        ->and($memberships->pluck('user_id')->unique())->toHaveCount(10);
});

it('gives seeded Management accounts access to the Orchid admin shell', function () {
    $this->seed(DatabaseSeeder::class);

    $management = User::query()->where('email', 'avery.management@example.com')->sole();
    $ranger = User::query()->where('email', 'casey.ranger@example.com')->sole();

    expect($management->hasAccess('platform.index'))->toBeTrue()
        ->and($management->hasAccess('platform.systems.users'))->toBeFalse()
        ->and($management->hasAccess('platform.systems.roles'))->toBeFalse()
        ->and($ranger->hasAccess('platform.index'))->toBeFalse()
        ->and($ranger->hasAccess('platform.systems.users'))->toBeFalse()
        ->and($ranger->hasAccess('platform.systems.roles'))->toBeFalse();
});

it('limits identity membership roles to Ranger and Management only', function () {
    expect(OrganizationRole::values())->toBe([
        'ranger',
        'management',
    ]);
});

it('builds member profile fixtures within their membership organization', function () {
    $profile = MemberProfile::factory()->create();

    expect($profile->organization_id)->toBe($profile->membership->organization_id)
        ->and($profile->organization->is($profile->membership->organization))->toBeTrue();
});

it('does not introduce qualification seniority or eligibility fields', function () {
    $identityColumns = collect([
        ...Schema::getColumnListing('organizations'),
        ...Schema::getColumnListing('organization_memberships'),
        ...Schema::getColumnListing('member_profiles'),
    ]);

    expect($identityColumns)->not->toContain(
        'qualification',
        'qualifications',
        'seniority',
        'seniority_rank',
        'eligibility',
        'eligibility_role',
        'skills',
        'certifications'
    );
});
