<style>
    @media (max-width: 767px) {
        .matrix, .matrix tbody, .matrix tr, .matrix th { display: block; width: 100%; }
        .matrix thead { display: none; }
        .matrix tr:not(.add-row) { padding: .5rem; margin-bottom: 1rem; border: 1px solid #d5d9dd; }
        .matrix a { min-height: 44px; display: inline-flex; align-items: center; }
        .matrix th:has(select)::before, .matrix th:has(input)::before { display: block; padding: .3rem .75rem 0; font-weight: normal; }
        .matrix th:has(select[aria-label$="shift"])::before { content: 'Shift'; }
        .matrix th:has(select[aria-label$="classification"])::before { content: 'Classification'; }
        .matrix th:has(select[aria-label="Override weekday"])::before { content: 'Weekday'; }
        .matrix th:has(input[aria-label="Override date"])::before { content: 'Date'; }
    }
</style>
<div class="bg-white rounded p-4 mb-3">
    <h2 class="h5">{{ $season->name }} · {{ $phase->name }}</h2>
    <p>{{ \Carbon\CarbonImmutable::parse($phase->starts_on, 'UTC')->format('D, M j, Y') }}
        through {{ \Carbon\CarbonImmutable::parse($phase->ends_on, 'UTC')->format('D, M j, Y') }} · {{ $season->timezone }}</p>
    <p><a href="{{ app(\App\Orchid\ManagementYear::class)->route('platform.seasons', [$organization->id, $season->id]) }}">Back to season and phases</a> ·
        <a href="{{ app(\App\Orchid\ManagementYear::class)->route('platform.phase-coverage', [$organization->id, $phase->id]) }}">Configure shifts and coverage</a></p>
    @if($configuration['requires_review'])
        <p role="status">Policies need configuration or review. Configure shifts first, then save policies against the current season and coverage.</p>
    @else
        <p role="status">Policies saved · Revision {{ $configuration['revision'] }}</p>
    @endif
    <p>These settings are future scheduling inputs. Warning thresholds apply when hours exceed the entered value; they never prevent reporting worked time. Existing schedules and hours are preserved.</p>
    <p class="mb-0">Employment and meeting-pay rules require validation before payroll use. Mixed-phase weekly calculations remain pending. Saving these settings does not certify legal compliance.</p>
</div>
