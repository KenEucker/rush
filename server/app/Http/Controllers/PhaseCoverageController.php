<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Seasons\PhaseCoverage;
use App\Services\Seasons\SavePhaseCoverage;
use Illuminate\Http\Request;

class PhaseCoverageController extends Controller
{
    public function show(Request $request, Organization $organization, string $phase, PhaseCoverage $coverage): array
    {
        return $coverage->snapshot($coverage->phase($request->user(), $organization, $phase));
    }

    public function save(Request $request, Organization $organization, string $phase, SavePhaseCoverage $service): array
    {
        return $service->handle($request->user(), $organization, $phase, $request->all());
    }
}
