<div class="bg-white rounded p-4 mb-3">
    <p>Every accepted range revision is a notice to Management. Acknowledging confirms it was seen; it does not repair the schedule or confirm coverage.</p>
    <p><a href="{{ route('platform.unavailability', [$organization->id, 'new']) }}">Add unavailability on behalf of a Ranger</a></p>
    @if($reportId)
        <p><a href="{{ route('platform.unavailability', [$organization->id, 'new']) }}">View all notices</a></p>
    @endif
    <p>Times below are UTC. Overnight intervals include both dates. Enter exact UTC instants in the editor.</p>
    @forelse($reports as $notice)
        <section class="border rounded p-3 mb-3" data-testid="management-notice" data-record-id="{{ $notice['id'] }}">
            <h2 class="h5">{{ $rangerNames[$notice['membership_id']] ?? 'Ranger' }} · Revision {{ $notice['revision'] }}</h2>
            <p>{{ \Carbon\CarbonImmutable::parse($notice['starts_at'])->format('D, M j Y g:i A') }} → {{ \Carbon\CarbonImmutable::parse($notice['ends_at'])->format('D, M j Y g:i A') }} UTC</p>
            <p>{{ $notice['acknowledged_at'] ? 'Acknowledged as seen · '.$notice['acknowledged_at'] : 'Needs acknowledgment' }}</p>
            @foreach($notice['assignment_conflicts'] as $conflict)
                <div class="alert alert-warning" role="alert">
                    <strong>Assignment conflict · Affected coverage gap needs review</strong>
                    <p>This assignment cannot be treated as reliable coverage. No replacement or Management coverage is confirmed.</p>
                    <p>{{ $conflict['affected_coverage']['target_name'] ?? 'Coverage target not yet configured' }} ·
                        {{ \Carbon\CarbonImmutable::parse($conflict['affected_coverage']['starts_at'])->format('D, M j Y g:i A') }} →
                        {{ \Carbon\CarbonImmutable::parse($conflict['affected_coverage']['ends_at'])->format('D, M j Y g:i A') }} UTC</p>
                    <small>Assignment {{ $conflict['id'] }} · Revision {{ $conflict['revision'] }}</small>
                </div>
            @endforeach
            <a href="{{ route('platform.unavailability', [$organization->id, $notice['id']]) }}">Edit range</a>
            @unless($notice['acknowledged_at'])
                {!! \Orchid\Screen\Actions\Button::make('Acknowledge as seen')->method('acknowledge', ['notice_id' => $notice['id'], 'notice_revision' => $notice['revision']])->novalidate() !!}
            @endunless
        </section>
    @empty
        <p>No unavailability notices.</p>
    @endforelse
    <h2 class="h5">{{ $reportId ? 'Edit time range' : 'Add time range' }}</h2>
</div>
