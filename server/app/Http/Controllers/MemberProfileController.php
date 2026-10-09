<?php

namespace App\Http\Controllers;

use App\Http\Resources\MemberProfileResource;
use App\Models\MemberProfile;
use App\Models\Organization;
use App\Services\Identity\UpdateMemberProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MemberProfileController extends Controller
{
    public function show(Organization $organization, MemberProfile $profile): MemberProfileResource
    {
        Gate::authorize('view', $organization);
        abort_unless($profile->organization_id === $organization->id, 404);
        Gate::authorize('view', $profile);

        return new MemberProfileResource($profile);
    }

    public function update(Request $request, Organization $organization, MemberProfile $profile, UpdateMemberProfile $update): MemberProfileResource
    {
        Gate::authorize('view', $organization);
        abort_unless($profile->organization_id === $organization->id, 404);
        Gate::authorize('update', $profile);
        $profile = $update->handle($request->user(), $profile, $request->all());

        return new MemberProfileResource($profile);
    }
}
