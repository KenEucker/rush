<?php

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Models\MemberProfile;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $organization = Organization::query()->updateOrCreate(
            ['slug' => 'rush-demo-rangers'],
            [
                'name' => 'RUSH Demo Rangers',
                'timezone' => 'America/Los_Angeles',
            ]
        );

        $members = [
            ['Avery Morgan', 'avery.management@example.com', OrganizationRole::Management, 'Avery'],
            ['Riley Chen', 'riley.management@example.com', OrganizationRole::Management, 'Riley'],
            ['Casey Brooks', 'casey.ranger@example.com', OrganizationRole::Ranger, 'Casey'],
            ['Jordan Lee', 'jordan.ranger@example.com', OrganizationRole::Ranger, 'Jordan'],
            ['Taylor Singh', 'taylor.ranger@example.com', OrganizationRole::Ranger, 'Taylor'],
            ['Morgan Patel', 'morgan.ranger@example.com', OrganizationRole::Ranger, 'Morgan'],
            ['Quinn Rivera', 'quinn.ranger@example.com', OrganizationRole::Ranger, 'Quinn'],
            ['Sam Nguyen', 'sam.ranger@example.com', OrganizationRole::Ranger, 'Sam'],
            ['Jamie Ortiz', 'jamie.ranger@example.com', OrganizationRole::Ranger, 'Jamie'],
            ['Robin Garcia', 'robin.ranger@example.com', OrganizationRole::Ranger, 'Robin'],
        ];

        foreach ($members as [$name, $email, $role, $displayName]) {
            $this->seedMember($organization, $name, $email, $role, $displayName);
        }
    }

    private function seedMember(
        Organization $organization,
        string $name,
        string $email,
        OrganizationRole $role,
        string $displayName
    ): void {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'permissions' => $this->orchidPermissionsFor($role),
                'remember_token' => Str::random(10),
            ]
        );

        $membership = OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $role,
                'is_active' => true,
            ]
        );

        MemberProfile::query()->updateOrCreate(
            ['organization_membership_id' => $membership->id],
            [
                'organization_id' => $organization->id,
                'display_name' => $displayName,
            ]
        );
    }

    /**
     * @return array<string, int>
     */
    private function orchidPermissionsFor(OrganizationRole $role): array
    {
        if ($role === OrganizationRole::Management) {
            return [
                'platform.index' => 1,
                'platform.systems.roles' => 1,
                'platform.systems.users' => 1,
            ];
        }

        return [
            'platform.index' => 0,
            'platform.systems.roles' => 0,
            'platform.systems.users' => 0,
        ];
    }
}
