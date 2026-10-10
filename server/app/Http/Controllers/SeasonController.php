<?php

namespace App\Http\Controllers;

use App\Http\Resources\SeasonResource;
use App\Models\Organization;
use App\Models\Season;
use App\Services\Seasons\SaveSeason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SeasonController extends Controller
{
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('manage', $organization);

        return SeasonResource::collection(Season::query()->where('organization_id', $organization->id)
            ->with('phases')->orderBy('starts_on')->orderBy('id')->paginate(25));
    }

    public function show(Request $request, Organization $organization, string $season): SeasonResource
    {
        Gate::forUser($request->user())->authorize('manage', $organization);

        return new SeasonResource(Season::query()->where('organization_id', $organization->id)->with('phases')->findOrFail($season));
    }

    public function save(Request $request, Organization $organization, string $season, SaveSeason $service): SeasonResource
    {
        return new SeasonResource($service->handle($request->user(), $organization, $season, $request->all()));
    }
}
