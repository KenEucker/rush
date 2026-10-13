<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\PreferenceProfile;
use App\Models\User;

class PreferenceProfilePolicy
{
    public function create(User $actor, OrganizationMembership $membership): bool
    {
        return $actor->organizationMemberships()->whereKey($membership->id)
            ->where('is_active', true)->where('role', OrganizationRole::Ranger)->exists();
    }

    public function update(User $actor, PreferenceProfile $profile): bool
    {
        return $actor->organizationMemberships()->whereKey($profile->organization_membership_id)
            ->where('organization_id', $profile->organization_id)
            ->where('is_active', true)->where('role', OrganizationRole::Ranger)->exists();
    }

    public function view(User $actor, PreferenceProfile $profile): bool
    {
        return $this->update($actor, $profile);
    }
}
