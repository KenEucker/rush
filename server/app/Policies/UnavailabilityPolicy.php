<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\Unavailability;
use App\Models\User;

class UnavailabilityPolicy
{
    public function create(User $actor, OrganizationMembership $membership): bool
    {
        return $actor->organizationMemberships()->whereKey($membership->id)
            ->where('is_active', true)->where('role', OrganizationRole::Ranger)->exists();
    }

    public function update(User $actor, Unavailability $record): bool
    {
        return $actor->organizationMemberships()->whereKey($record->organization_membership_id)
            ->where('organization_id', $record->organization_id)
            ->where('is_active', true)->where('role', OrganizationRole::Ranger)->exists();
    }

    public function view(User $actor, Unavailability $record): bool
    {
        return $this->update($actor, $record);
    }
}
