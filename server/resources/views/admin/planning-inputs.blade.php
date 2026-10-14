<div class="bg-white rounded shadow-sm p-4 mb-4">
    <h2 class="h4">Constraint policy</h2>
    <p>Automatic proposals respect availability, overlapping work, weekly caps and minimum rest. Management overrides require a reason and retain visible conflicts. Published commitments are preserved. Coverage and event shortfalls stay visible; weekly fairness precedes season fairness and positive preferences.</p>
    <p>Weekly policies are prorated by phase calendar days divided by seven. Overlapping paid event and shift intervals count once. A partial planning range still uses full calendar-week commitments.</p>
    @if ($planning)
        <h2 class="h4">Captured input review</h2>
        <p>Snapshot {{ $planning['id'] }} · {{ $planning['created_at'] }}</p>
        <div role="status" class="alert {{ $planning['stale'] ? 'alert-warning' : 'alert-info' }}">
            {{ $planning['stale'] ? 'Inputs have changed. Capture a new snapshot before generating a draft.' : 'Input revisions still match the Server.' }} No assignments have been generated or changed.
        </div>
        <p>{{ count($planning['inputs']['opportunities']) }} shift/event opportunities · {{ count($planning['inputs']['commitments']) }} existing organization commitments</p>
        @foreach ($planning['inputs']['issues'] as $issue)
            <div class="alert alert-warning">{{ str_replace('_', ' ', $issue['code']) }}: {{ $issue['date'] ?? $issue['key'] ?? $issue['event_id'] ?? '' }}</div>
        @endforeach
        <div class="table-responsive">
            <table class="table">
                <caption>Weekly scheduling policies, including days outside the selected range</caption>
                <thead class="text-nowrap"><tr><th>Week beginning</th><th>Target hours</th><th>Cap hours</th><th>Warning hours</th></tr></thead>
                <tbody class="text-nowrap">
                @foreach ($planning['inputs']['weeks'] as $date => $week)
                    <tr><th>{{ \Carbon\CarbonImmutable::parse($date)->format('D, M j, Y') }}</th>
                    @foreach (['weekly_target_minutes', 'planned_cap_minutes', 'planned_warning_minutes'] as $field)
                        <td>{{ $week['policy_minute_sevenths'][$field] === null ? 'Unconfigured — review required' : number_format($week['policy_minute_sevenths'][$field] / 420, 2) }}</td>
                    @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="table-responsive">
            <table class="table">
                <caption>Whole-shift and event demand · {{ $planning['inputs']['season']['timezone'] }} · Not assignments</caption>
                <thead class="text-nowrap"><tr><th>Work</th><th>Coverage</th><th>Start</th><th>End</th><th>Positions</th></tr></thead>
                <tbody class="text-nowrap">
                @foreach ($planning['inputs']['opportunities'] as $opportunity)
                    @php
                        $start = \Carbon\CarbonImmutable::parse($opportunity['starts_at'])->setTimezone($planning['inputs']['season']['timezone']);
                        $end = \Carbon\CarbonImmutable::parse($opportunity['ends_at'])->setTimezone($planning['inputs']['season']['timezone']);
                    @endphp
                    <tr><th>{{ $opportunity['name'] }}</th><td>{{ $opportunity['target_name'] ?? 'Independent event target' }}</td>
                        <td>{{ $start->format('D, M j, Y g:i A T') }}</td><td>{{ $end->format('D, M j, Y g:i A T') }}{{ $start->toDateString() !== $end->toDateString() ? ' (overnight)' : '' }}</td><td>{{ $opportunity['positions'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <details><summary>Existing weekly committed hours</summary>
            <ul>
            @foreach ($planning['inputs']['weeks'] as $date => $week)
                @foreach ($week['committed_seconds'] ?? [] as $memberId => $seconds)
                    <li>{{ collect($planning['inputs']['roster'])->firstWhere('id', $memberId)['name'] }} · Week {{ $date }}: {{ number_format($seconds / 3600, 2) }} hours</li>
                @endforeach
            @endforeach
            </ul>
        </details>
        <details><summary>Inspect immutable input sources and revisions</summary><pre class="text-wrap">{{ json_encode($planning['inputs'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
    @endif
</div>
