<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Sync\PullChanges;
use App\Services\Sync\PushOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function push(Request $request, Organization $organization, PushOperation $service): JsonResponse
    {
        return response()->json($service->handle($request->user(), $organization, $request->all()));
    }

    public function pull(Request $request, Organization $organization, PullChanges $service): JsonResponse
    {
        return response()->json($service->handle($request->user(), $organization, $request->all()));
    }
}
