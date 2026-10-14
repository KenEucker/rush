<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EventConfiguration
{
    public function season(User $actor, Organization $organization, string $id): Season
    {
        Gate::forUser($actor)->authorize('manage', $organization);

        return Season::query()->where('organization_id', $organization->id)->findOrFail($id);
    }

    public function record(User $actor, Organization $organization, string $season, string $id): Event
    {
        $this->season($actor, $organization, $season);

        return Event::query()->where('organization_id', $organization->id)->where('season_id', $season)->findOrFail($id);
    }

    public function snapshot(Event $event): array
    {
        return [...$event->only(['id', 'organization_id', 'season_id', 'name', 'timezone', 'season_revision', 'paid', 'target_type', 'target_value', 'counts_scheduled', 'revision']),
            'starts_at' => $event->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $event->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'attendance' => DB::table('event_attendances')->where('event_id', $event->id)->where('is_assigned', true)
                ->orderBy('organization_membership_id')->get(['id', 'organization_membership_id'])->map(fn ($row) => (array) $row)->all()];
    }

    public function describe(Event $event): array
    {
        return [...$this->snapshot($event), 'requires_review' => $event->season_revision !== $event->season->revision,
            'evaluation' => app(EventStaffing::class)->evaluate($event)];
    }
}
