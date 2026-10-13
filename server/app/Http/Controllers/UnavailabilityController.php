<?php

namespace App\Http\Controllers;

use App\Http\Resources\UnavailabilityResource;
use App\Models\Organization;
use App\Services\Availability\ManageUnavailability;
use Illuminate\Http\Request;

class UnavailabilityController extends Controller
{
    public function index(Request $request, Organization $organization, ManageUnavailability $service)
    {
        return UnavailabilityResource::collection($service->reports($request->user(), $organization));
    }

    public function save(Request $request, Organization $organization, string $report, ManageUnavailability $service): UnavailabilityResource
    {
        return new UnavailabilityResource($service->save($request->user(), $organization, $report, $request->all()));
    }

    public function acknowledge(Request $request, Organization $organization, string $report, ManageUnavailability $service): UnavailabilityResource
    {
        return new UnavailabilityResource($service->acknowledge($request->user(), $organization, $report, $request->all()));
    }
}
