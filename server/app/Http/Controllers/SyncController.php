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
        abort_if($request->hasHeader('X-RUSH-Account') && $request->header('X-RUSH-Account') !== (string) $request->user()->id, 403, 'The signed-in account changed. Sign in again to reopen your saved work.');

        return response()->json($service->handle($request->user(), $organization, $request->all(), $request->header('X-RUSH-Membership')));
    }

    public function pull(Request $request, Organization $organization, PullChanges $service): JsonResponse
    {
        abort_if($request->hasHeader('X-RUSH-Account') && $request->header('X-RUSH-Account') !== (string) $request->user()->id, 403, 'The signed-in account changed. Sign in again to reopen your saved work.');

        return response()->json($service->handle($request->user(), $organization, $request->all(), $request->header('X-RUSH-Membership')));
    }
}
