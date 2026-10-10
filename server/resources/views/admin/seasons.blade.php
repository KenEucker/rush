<style>
    /* Keep the phase identity hidden and make each editable row usable on phones. */
    .matrix[data-controller="matrix"] tr > :nth-child(4) { display: none; }
    @media (max-width: 767px) {
        .matrix[data-controller="matrix"],
        .matrix[data-controller="matrix"] tbody,
        .matrix[data-controller="matrix"] tr,
        .matrix[data-controller="matrix"] th { display: block; width: 100%; }
        .matrix[data-controller="matrix"] thead,
        .matrix[data-controller="matrix"] tr > :nth-child(4) { display: none; }
        .matrix[data-controller="matrix"] tr:not(.add-row) { margin-bottom: 1rem; }
        .matrix[data-controller="matrix"] tbody tr:not(.add-row) > th::before {
            display: block; padding: .4rem .75rem 0; font-weight: normal;
        }
        .matrix[data-controller="matrix"] tbody tr:not(.add-row) > th:nth-child(1)::before { content: 'Phase name'; }
        .matrix[data-controller="matrix"] tbody tr:not(.add-row) > th:nth-child(2)::before { content: 'Starts on'; }
        .matrix[data-controller="matrix"] tbody tr:not(.add-row) > th:nth-child(3)::before { content: 'Ends on (inclusive)'; }
        .matrix[data-controller="matrix"] a { min-height: 44px; display: inline-flex; align-items: center; }
    }
</style>
<div class="bg-white rounded p-4 mb-3">
    <h2 class="h5">{{ $organization->name }}</h2>
    <a href="{{ route('platform.seasons', [$organization->id, 'new']) }}">Create a new season</a>
    <ul class="mt-3">
        @forelse($seasons as $item)
            <li class="mb-2">
                <a href="{{ route('platform.seasons', [$organization->id, $item->id]) }}">{{ $item->name }}</a>
                <div class="text-muted">
                    {{ \Carbon\CarbonImmutable::parse($item->starts_on, 'UTC')->format('D, M j, Y') }}
                    through {{ \Carbon\CarbonImmutable::parse($item->ends_on, 'UTC')->format('D, M j, Y') }}
                    · {{ $item->timezone }} · {{ $item->phases->count() }} phases · Revision {{ $item->revision }}
                </div>
            </li>
        @empty
            <li>No seasons configured yet.</li>
        @endforelse
    </ul>
    {{ $seasons->links() }}
    <p class="mb-0">Saving changes calendar configuration only. Existing assignment instants and reported hours are preserved.</p>
</div>
