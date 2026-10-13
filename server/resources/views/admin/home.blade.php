<div class="bg-white rounded p-4">
    <p>You are signed in with an active Management membership.</p>
    <p>Configure seasons, coverage, events and attendance. A server connection is required.</p>
    <ul>
        @foreach($organizations as $organization)
            <li><a href="{{ app(\App\Orchid\ManagementYear::class)->route('platform.seasons', [$organization->id, 'new']) }}">Seasons and phases — {{ $organization->name }}</a></li>
            <li><a href="{{ app(\App\Orchid\ManagementYear::class)->route('platform.unavailability', [$organization->id, 'new']) }}">Unavailability notices — {{ $organization->name }} ({{ $organization->unavailability_notice_count }} need acknowledgment)</a></li>
        @endforeach
    </ul>
    <section id="events" aria-labelledby="events-heading" class="mt-4">
        <h2 id="events-heading" class="h5">Events and attendance</h2>
        <p>Choose a season to create or edit one-off events, assign Rangers and review staffing targets.</p>
        @foreach($organizations as $organization)
            <h3 class="h6">{{ $organization->name }}</h3>
            <ul>
                @forelse($organization->seasons as $season)
                    <li><a href="{{ app(\App\Orchid\ManagementYear::class)->route('platform.events', [$organization->id, $season->id, 'new']) }}">Events and attendance — {{ $season->name }}</a></li>
                @empty
                    @if(app(\App\Orchid\ManagementYear::class)->context()['readOnly'])
                        <li>No seasons in this year.</li>
                    @else
                        <li><a href="{{ app(\App\Orchid\ManagementYear::class)->route('platform.seasons', [$organization->id, 'new']) }}">Create a season to configure events</a></li>
                    @endif
                @endforelse
            </ul>
        @endforeach
    </section>
    <a href="/">Return to RUSH</a>
</div>
