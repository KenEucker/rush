<?php

namespace App\Services\Identity;

use App\Models\User;

class SessionIdentity
{
    public function forUser(User $user): array
    {
        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'memberships' => $user->organizationMemberships()->where('is_active', true)
                ->with(['organization', 'profile'])->orderBy('id')->get()->map(fn ($membership) => [
                    'id' => $membership->id,
                    'role' => $membership->role->value,
                    'organization' => $membership->organization->only(['id', 'name']),
                    'profile' => $membership->profile?->only(['id', 'display_name', 'phone']),
                ])->all(),
        ];
    }
}
