<div class="bg-white rounded shadow-sm p-4 mb-4">
    <h2 class="h4">Draft proposals</h2>
    <p>Revision {{ $draft['revision'] }} · {{ $draft['snapshot']['inputs']['season']['timezone'] }} · Saved on the Server. These assignments are not official or paid hours.</p>
    <p>Proposals use a stable feasibility order. Fairness optimization is not yet available.</p>
    <div role="status" class="alert {{ $draft['snapshot']['stale'] ? 'alert-warning' : 'alert-info' }}">
        {{ $draft['snapshot']['stale'] ? 'Planning sources have changed. This draft is preserved; capture fresh inputs and generate a new draft before editing.' : 'Input revisions still match the Server.' }}
    </div>
    <a href="{{ route('platform.planning-inputs', [$organization->id, $seasonId, 'snapshot' => $draft['snapshot']['id']]) }}">Review planning inputs and saved drafts</a>
    <a class="ms-3" href="{{ route('platform.drafts', [$organization->id, $seasonId, $draft['id']]) }}">Add assignment</a>
    @foreach ($draft['snapshot']['inputs']['issues'] as $issue)
        <p class="alert alert-warning">Configuration requires review: {{ str_replace('_', ' ', $issue['code']) }}</p>
    @endforeach
    <div class="table-responsive position-relative mt-3">
        <table class="table">
            <caption>Proposed assignments · Select Edit for reassignment or partial coverage</caption>
            <thead class="text-nowrap"><tr><th>Ranger</th><th>Work / coverage</th><th>Start</th><th>End</th><th>Review</th><th>Action</th></tr></thead>
            <tbody class="text-nowrap">
            @forelse ($draft['assignments'] as $assignment)
                @php
                    $zone = $draft['snapshot']['inputs']['season']['timezone'];
                    $start = \Carbon\CarbonImmutable::parse($assignment['starts_at'])->setTimezone($zone);
                    $end = \Carbon\CarbonImmutable::parse($assignment['ends_at'])->setTimezone($zone);
                    $partial = $assignment['starts_at'] !== $assignment['context']['starts_at'] || $assignment['ends_at'] !== $assignment['context']['ends_at'];
                @endphp
                <tr>
                    <th>{{ collect($draft['snapshot']['inputs']['roster'])->firstWhere('id', $assignment['membership_id'])['name'] }}</th>
                    <td>{{ $assignment['context']['name'] }} · {{ $assignment['context']['target_name'] ?? 'Event' }}{{ $partial ? ' (partial)' : '' }}</td>
                    <td>{{ $start->format('D, M j, Y g:i A T') }}</td>
                    <td>{{ $end->format('D, M j, Y g:i A T') }}{{ $start->toDateString() !== $end->toDateString() ? ' (overnight)' : '' }}</td>
                    <td>
                        @foreach ($assignment['evaluation']['overridable'] as $conflict)
                            <div class="text-danger">Conflict: {{ str_replace('_', ' ', $conflict['code']) }}</div>
                        @endforeach
                        @foreach ($assignment['evaluation']['warnings'] as $warning)
                            <div>Warning: {{ str_replace('_', ' ', $warning['code']) }}</div>
                        @endforeach
                        @if ($assignment['override_reason']) <div>Override: {{ $assignment['override_reason'] }}</div> @endif
                    </td>
                    <td><a href="{{ route('platform.drafts', [$organization->id, $seasonId, $draft['id'], 'assignment' => $assignment['id']]) }}">Edit <span class="visually-hidden">{{ $assignment['context']['name'] }} assignment</span></a></td>
                </tr>
            @empty
                <tr><td colspan="6">No proposed assignments. Review constraints and unmet demand below.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <h2 class="h4">Unmet demand</h2>
    <p>Area coverage and event targets are evaluated independently. Unavailable or inactive Rangers do not count. Review all assignment conflicts above before relying on coverage.</p>
    <ul>
        @forelse (array_filter($draft['demand'], fn ($d) => $d['maximum_shortfall'] > 0) as $demand)
            <li>{{ $demand['opportunity']['name'] }} · {{ $demand['opportunity']['target_name'] ?? 'Event' }} · {{ \Carbon\CarbonImmutable::parse($demand['opportunity']['starts_at'])->setTimezone($draft['snapshot']['inputs']['season']['timezone'])->format('D, M j g:i A') }}: up to {{ $demand['maximum_shortfall'] }} unfilled position(s).
                <details><summary>Unfilled intervals</summary><ul>
                    @foreach ($demand['segments'] as $segment)
                        @if ($segment['shortfall'] > 0)
                            <li>{{ \Carbon\CarbonImmutable::parse($segment['starts_at'])->setTimezone($draft['snapshot']['inputs']['season']['timezone'])->format('D, M j g:i A T') }} – {{ \Carbon\CarbonImmutable::parse($segment['ends_at'])->setTimezone($draft['snapshot']['inputs']['season']['timezone'])->format('D, M j g:i A T') }}: {{ $segment['shortfall'] }}</li>
                        @endif
                    @endforeach
                </ul></details>
            </li>
        @empty
            <li>No unmet demand in the captured inputs. Review conflicts and source freshness before publication.</li>
        @endforelse
    </ul>
</div>
