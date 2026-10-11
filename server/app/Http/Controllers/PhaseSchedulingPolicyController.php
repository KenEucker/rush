<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Seasons\PhaseCoverage;
use App\Services\Seasons\PhaseSchedulingPolicy;
use App\Services\Seasons\SavePhaseSchedulingPolicy;
use Illuminate\Http\Request;

class PhaseSchedulingPolicyController extends Controller
{
    public function show(Request $request, Organization $organization, string $phase, PhaseCoverage $coverage, PhaseSchedulingPolicy $policies): array
    {
        return $policies->snapshot($coverage->phase($request->user(), $organization, $phase));
    }

    public function save(Request $request, Organization $organization, string $phase, SavePhaseSchedulingPolicy $service): array
    {
        return $service->handle($request->user(), $organization, $phase, $request->all());
    }
}
