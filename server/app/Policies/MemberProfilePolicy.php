<?php

namespace App\Policies;

use App\Models\MemberProfile;
use App\Models\User;

class MemberProfilePolicy
{
    public function view(User $user, MemberProfile $profile): bool
    {
        return $this->update($user, $profile)
            || $user->can('manage', $profile->organization);
    }

    public function update(User $user, MemberProfile $profile): bool
    {
        return $user->organizationMemberships()->whereKey($profile->organization_membership_id)
            ->where('organization_id', $profile->organization_id)->where('is_active', true)->exists();
    }
}
