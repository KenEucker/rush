<?php

namespace App\Services\Identity;

use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UpdateMemberProfile
{
    public function handle(User $actor, MemberProfile $profile, array $attributes): MemberProfile
    {
        Gate::forUser($actor)->authorize('update', $profile);
        $profile->update(array_intersect_key($attributes, array_flip(['display_name', 'phone'])));

        return $profile->refresh();
    }
}
