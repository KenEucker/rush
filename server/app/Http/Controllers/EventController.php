<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Organization;
use App\Services\Events\EventConfiguration;
use App\Services\Events\SaveEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request, Organization $organization, string $season, EventConfiguration $configuration): JsonResponse
    {
        $configuration->season($request->user(), $organization, $season);

        return response()->json(Event::query()->where('organization_id', $organization->id)->where('season_id', $season)
            ->orderBy('starts_at')->orderBy('id')->paginate(25)->through(fn ($event) => $configuration->describe($event)));
    }

    public function show(Request $request, Organization $organization, string $season, string $event, EventConfiguration $configuration): JsonResponse
    {
        return response()->json($configuration->describe($configuration->record($request->user(), $organization, $season, $event)));
    }

    public function save(Request $request, Organization $organization, string $season, string $event, SaveEvent $service, EventConfiguration $configuration): JsonResponse
    {
        $record = $service->handle($request->user(), $organization, $season, $event, $request->all());

        return response()->json($configuration->describe($record), $record->wasRecentlyCreated ? 201 : 200);
    }
}
