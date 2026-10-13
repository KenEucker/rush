<div class="bg-white rounded p-4 mb-3">
    <h2 class="h5">{{ $season->name }} · {{ $season->timezone }}</h2>
    <p>Events happen once. A skipped week requires no recurrence cancellation. Attendance does not fill or repair ordinary area coverage.</p>
    <a href="{{ route('platform.events', [$organization->id, $season->id, 'new']) }}">Create a one-off event</a>
    <ul class="mt-3">
        @forelse($events as $item)
            <li class="mb-3">
                <a href="{{ route('platform.events', [$organization->id, $season->id, $item->id]) }}">{{ $item->name }}</a>
                <div>{{ $item->starts_at->setTimezone($item->timezone)->format('D, M j, Y g:i A') }} – {{ $item->ends_at->setTimezone($item->timezone)->format('D, M j, Y g:i A') }} · {{ $item->timezone }}</div>
            </li>
        @empty
            <li>No events configured.</li>
        @endforelse
    </ul>
    {{ $events->links() }}
    @if($event)
        @if($event['requires_review'])
            <p role="alert">Season configuration changed. Review the event dates and save again. Existing event instants have been preserved.</p>
        @endif
        <h3 class="h6">Event staffing evaluation</h3>
        <p>Target: {{ $event['evaluation']['required'] ?? 'None' }} · Active Rangers: {{ $event['evaluation']['active_rangers'] }} · Minimum counted throughout event: {{ $event['evaluation']['minimum_counted'] }} · Maximum shortfall: {{ $event['evaluation']['maximum_shortfall'] ?? 'No target' }}</p>
        <p>Rangers are counted once per interval. Unavailable or inactive Rangers do not satisfy the target. Scheduled shifts count across all areas only when enabled.</p>
        @foreach($event['evaluation']['segments'] as $segment)
            <div class="mb-2">{{ \Carbon\CarbonImmutable::parse($segment['starts_at'])->setTimezone($season->timezone)->format('D, M j g:i A') }} – {{ \Carbon\CarbonImmutable::parse($segment['ends_at'])->setTimezone($season->timezone)->format('D, M j g:i A') }}: {{ $segment['counted'] }} counted, {{ $segment['shortfall'] ?? 'no target' }} shortfall</div>
        @endforeach
        @foreach(['unavailable_membership_ids' => 'Unavailability conflict', 'inactive_membership_ids' => 'Inactive or no longer a Ranger'] as $field => $label)
            @foreach($event['evaluation'][$field] as $member)
                <p role="alert">{{ $label }}: {{ $members[$member] ?? 'Former member' }}. Review the attendance assignment.</p>
            @endforeach
        @endforeach
        <h3 class="h6">Assigned attendance</h3>
        <ul>
            @forelse($event['attendance'] as $attendance)
                <li>{{ $members[$attendance['organization_membership_id']] ?? 'Former member' }} · {{ $event['paid'] ? 'Paid' : 'Unpaid' }} · Full event</li>
            @empty
                <li>No explicit attendance assignments.</li>
            @endforelse
        </ul>
        <p>Paid attendance is an input to weekly scheduling and timesheets. This configuration view does not finalize hours or add pay for overlapping scheduled time.</p>
    @endif
</div>
