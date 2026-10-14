<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Scheduling\PlanningConstraints;
use App\Services\Scheduling\PlanningInputs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanningInputController extends Controller
{
    public function store(Request $request, Organization $organization, string $season, PlanningInputs $service): JsonResponse
    {
        return response()->json($service->capture($request->user(), $organization, $season, $request->all()), 201);
    }

    public function show(Request $request, Organization $organization, string $snapshot, PlanningInputs $service): JsonResponse
    {
        return response()->json($service->read($request->user(), $organization, $snapshot));
    }

    public function evaluate(Request $request, Organization $organization, string $snapshot, PlanningInputs $service, PlanningConstraints $constraints): JsonResponse
    {
        $record = $service->read($request->user(), $organization, $snapshot);

        return response()->json(['snapshot_id' => $snapshot, 'stale' => $record['stale'],
            ...$constraints->evaluate($record['inputs'], $request->all())]);
    }
}
