<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\OfficialAssignment;
use App\Models\OrganizationMembership;
use App\Models\Unavailability;
use App\Services\Seasons\SeasonCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Event-only evaluation. Never changes ordinary coverage, assignments or payable hours. */
class EventStaffing
{
    public function evaluate(Event $event): array
    {
        $active = OrganizationMembership::query()->where('organization_id', $event->organization_id)
            ->where('role', 'ranger')->where('is_active', true)->pluck('id')->all();
        $assigned = DB::table('event_attendances')->where('event_id', $event->id)->where('is_assigned', true)->pluck('organization_membership_id')->all();
        $shifts = OfficialAssignment::query()->where('organization_id', $event->organization_id)
            ->whereIn('organization_membership_id', $active)->where('starts_at', '<', $event->ends_at)->where('ends_at', '>', $event->starts_at)->get();
        $unavailable = Unavailability::query()->where('organization_id', $event->organization_id)
            ->where('starts_at', '<', $event->ends_at)->where('ends_at', '>', $event->starts_at)->get();
        $required = match ($event->target_type) {
            'number' => $event->target_value,
            'percentage' => (int) ceil(count($active) * $event->target_value / 100),
            default => null,
        };
        $cuts = [$event->starts_at->timestamp, $event->ends_at->timestamp];
        foreach ([...($event->counts_scheduled ? $shifts->all() : []), ...$unavailable] as $interval) {
            $cuts[] = max($event->starts_at->timestamp, $interval->starts_at->timestamp);
            $cuts[] = min($event->ends_at->timestamp, $interval->ends_at->timestamp);
        }
        $cuts = array_values(array_unique($cuts));
        sort($cuts);
        $segments = [];
        foreach (array_slice($cuts, 0, -1) as $index => $from) {
            $to = $cuts[$index + 1];
            $scheduled = $event->counts_scheduled ? $shifts->filter(fn ($shift) => $shift->starts_at->timestamp <= $from && $shift->ends_at->timestamp >= $to)->pluck('organization_membership_id')->all() : [];
            $blocked = $unavailable->filter(fn ($range) => $range->starts_at->timestamp < $to && $range->ends_at->timestamp > $from)->pluck('organization_membership_id')->all();
            $counted = array_values(array_diff(array_intersect(array_unique([...$assigned, ...$scheduled]), $active), $blocked));
            sort($counted);
            $segments[] = ['starts_at' => CarbonImmutable::createFromTimestampUTC($from)->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => CarbonImmutable::createFromTimestampUTC($to)->format('Y-m-d\TH:i:s\Z'),
                'counted_membership_ids' => $counted, 'counted' => count($counted),
                'shortfall' => $required === null ? null : max(0, $required - count($counted))];
        }

        return ['active_rangers' => count($active), 'required' => $required,
            'minimum_counted' => min(array_column($segments, 'counted')),
            'maximum_shortfall' => $required === null ? null : max(array_column($segments, 'shortfall')),
            'segments' => $segments,
            'unavailable_membership_ids' => array_values(array_intersect($assigned, $unavailable->pluck('organization_membership_id')->unique()->all())),
            'inactive_membership_ids' => array_values(array_diff($assigned, $active)),
            'paid_attendance' => $event->paid ? array_map(fn ($member) => ['membership_id' => $member,
                'allocations' => app(SeasonCalendar::class)->allocate($event->season, $event->starts_at, $event->ends_at)], $assigned) : [],
        ];
    }
}
