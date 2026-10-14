<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Scheduling\ScheduleDrafts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleDraftController extends Controller
{
    public function store(Request $request, Organization $organization, string $snapshot, ScheduleDrafts $service): JsonResponse
    {
        return response()->json($service->generate($request->user(), $organization, $snapshot, $request->all()), 201);
    }

    public function show(Request $request, Organization $organization, string $draft, ScheduleDrafts $service): JsonResponse
    {
        return response()->json($service->read($request->user(), $organization, $draft));
    }

    public function save(Request $request, Organization $organization, string $draft, ScheduleDrafts $service): JsonResponse
    {
        return response()->json($service->save($request->user(), $organization, $draft, $request->all()));
    }
}
