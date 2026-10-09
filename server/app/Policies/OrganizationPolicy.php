<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->organizationMemberships()->where('organization_id', $organization->id)
            ->where('is_active', true)->exists();
    }

    public function manage(User $user, Organization $organization): bool
    {
        return $user->organizationMemberships()->where('organization_id', $organization->id)
            ->where('is_active', true)->where('role', OrganizationRole::Management)->exists();
    }
}
