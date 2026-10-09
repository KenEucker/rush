<?php

namespace App\Http\Controllers;

use App\Models\MemberProfile;
use App\Models\Organization;
use App\Services\Identity\UpdateMemberProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MemberProfileController extends Controller
{
    public function show(Organization $organization, MemberProfile $profile)
    {
        Gate::authorize('view', $organization);
        abort_unless($profile->organization_id === $organization->id, 404);
        Gate::authorize('view', $profile);

        return response()->json($profile->only(['id', 'display_name', 'phone']));
    }

    public function update(Request $request, Organization $organization, MemberProfile $profile, UpdateMemberProfile $update)
    {
        Gate::authorize('view', $organization);
        abort_unless($profile->organization_id === $organization->id, 404);
        Gate::authorize('update', $profile);
        $attributes = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);
        $profile = $update->handle($request->user(), $profile, $attributes);

        return response()->json($profile->only(['id', 'display_name', 'phone']));
    }
}
