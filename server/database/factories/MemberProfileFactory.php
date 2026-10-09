<?php

namespace Database\Factories;

use App\Models\MemberProfile;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberProfile>
 */
class MemberProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_membership_id' => OrganizationMembership::factory(),
            'organization_id' => fn (array $attributes) => OrganizationMembership::query()
                ->findOrFail($attributes['organization_membership_id'])
                ->organization_id,
            'display_name' => fake()->firstName(),
            'phone' => fake()->optional()->phoneNumber(),
        ];
    }
}
