<style>
    .matrix th:has(input[type="hidden"]) { display: none; }
    @media (max-width: 767px) {
        .matrix, .matrix tbody, .matrix tr, .matrix th { display: block; width: 100%; }
        .matrix thead { display: none; }
        .matrix tr:not(.add-row) { padding: .5rem; margin-bottom: 1rem; border: 1px solid #d5d9dd; }
        .matrix th:has(input[type="hidden"]) { display: none; }
        .matrix a { min-height: 44px; display: inline-flex; align-items: center; }
        .matrix th:has(input)::before { display: block; padding: .3rem .75rem 0; font-weight: normal; }
        .matrix th:has(input[aria-label="Shift name"])::before { content: 'Shift name'; }
        .matrix th:has(input[aria-label="Shift local start"])::before { content: 'Local start'; }
        .matrix th:has(input[aria-label="Shift duration in minutes"])::before { content: 'Duration (minutes)'; }
        .matrix th:has(input[aria-label="Area name"])::before { content: 'Area name'; }
        .matrix th:has(input[aria-label="Group name"])::before { content: 'Group name'; }
        .matrix th:has(input[aria-label="Group area names"])::before { content: 'Area names, separated by commas'; }
        .matrix th:has(input[aria-label="Staffing shift name"])::before { content: 'Shift name'; }
        .matrix th:has(input[aria-label="Staffing dedicated area"])::before { content: 'Dedicated area'; }
        .matrix th:has(input[aria-label="Staffing shared group"])::before { content: 'Shared group'; }
        .matrix th:has(input[aria-label="Required positions"])::before { content: 'Positions'; }
    }
</style>
<div class="bg-white rounded p-4 mb-3">
    <h2 class="h5">{{ $season->name }} · {{ $phase->name }}</h2>
    <p>{{ \Carbon\CarbonImmutable::parse($phase->starts_on, 'UTC')->format('D, M j, Y') }}
        through {{ \Carbon\CarbonImmutable::parse($phase->ends_on, 'UTC')->format('D, M j, Y') }} · {{ $season->timezone }}</p>
    <a href="{{ route('platform.seasons', [$organization->id, $season->id]) }}">Back to season and phases</a>
    <p class="mt-3 mb-0">Changes configure future planning inputs. Existing assignment times and their saved area context stay intact. Partial assignments are recorded separately from whole shift definitions.</p>
</div>
