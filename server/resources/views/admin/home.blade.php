@php($year = app(\App\Orchid\ManagementYear::class))
<div class="bg-white rounded p-4">
    <h2 class="h4">{{ $year->context()['year'] }} overview</h2>
    <p class="text-muted">Season configuration and notices for the selected starting year.</p>
    @forelse($organizations as $organization)
        <section class="mt-4" aria-label="{{ $organization->name }} overview">
            <h3 class="h5">{{ $organization->name }}</h3>
            <dl class="row my-3">
                @foreach(['Seasons' => $organization->seasons->count(), 'Phases' => $organization->seasons->sum('phases_count'), 'One-off events' => $organization->seasons->sum('events_count'), 'Unacknowledged notices' => $organization->unavailability_notice_count] as $label => $count)
                    <div class="col-6 col-lg-3 mb-3">
                        <dt class="text-muted fw-normal">{{ $label }}</dt>
                        <dd class="h3 mb-0">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
            @if($organization->unavailability_notice_count)
                <p class="alert alert-info">
                    {{ $organization->unavailability_notice_count }} {{ $year->context()['readOnly'] ? 'historical notices remain unacknowledged.' : 'notices need acknowledgment.' }}
                    <a href="{{ $year->route('platform.unavailability', [$organization->id, 'new']) }}">Review notices</a>
                </p>
            @else
                <p>No unacknowledged notices in this year.</p>
            @endif
            @if($organization->seasons->isNotEmpty())
                <div class="table-responsive">
                    <table class="table" style="min-width: 36rem">
                        <caption>Seasons grouped by starting year. Dates use each season's time zone.</caption>
                        <thead><tr><th scope="col">Season</th><th scope="col">Dates</th><th scope="col">Phases</th><th scope="col">Events</th></tr></thead>
                        <tbody>
                            @foreach($organization->seasons as $season)
                                <tr>
                                    <th scope="row"><a href="{{ route('platform.seasons', [$organization->id, $season->id]) }}">{{ $season->name }}</a></th>
                                    <td>{{ \Carbon\CarbonImmutable::parse($season->starts_on)->format('D, M j, Y') }} – {{ \Carbon\CarbonImmutable::parse($season->ends_on)->format('D, M j, Y') }}<br><small>{{ $season->timezone }}</small></td>
                                    <td>{{ $season->phases_count }}</td>
                                    <td>{{ $season->events_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p>No seasons in this year.</p>
                @unless($year->context()['readOnly'])
                    <p><a href="{{ $year->route('platform.seasons', [$organization->id, 'new']) }}">Create a season to configure events</a></p>
                @endunless
            @endif
        </section>
    @empty
        <p>No organizations available.</p>
    @endforelse
</div>
