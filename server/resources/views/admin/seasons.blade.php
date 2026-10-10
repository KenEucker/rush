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
<div class="bg-white rounded p-4 mb-3" data-controller="season-phase-defaults">
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
                <ul>
                    @foreach($item->phases as $phase)
                        <li><a href="{{ route('platform.phase-coverage', [$organization->id, $phase->id]) }}">Configure shifts and coverage — {{ $phase->name }}</a></li>
                    @endforeach
                </ul>
            </li>
        @empty
            <li>No seasons configured yet.</li>
        @endforelse
    </ul>
    {{ $seasons->links() }}
    <p class="mb-0">Saving changes calendar configuration only. Existing assignment instants and reported hours are preserved.</p>
</div>

@push('scripts')
    <script>
        window.application.register('season-phase-defaults', class extends window.Controller {
            connect() {
                this.form = this.element.closest('form');
                const rows = this.form.querySelector('.matrix tbody');
                this.observer = new MutationObserver((changes) => {
                    for (const change of changes) {
                        for (const row of change.addedNodes) {
                            if (row instanceof HTMLTableRowElement && !row.classList.contains('add-row')) {
                                this.defaultStart(row);
                            }
                        }
                    }
                    this.updateEndDates();
                });
                this.observer.observe(rows, { childList: true });
                this.refreshEndDates = () => this.updateEndDates();
                this.suggestEndDate = (event) => {
                    const end = event.target;
                    const previous = this.focusedField;
                    this.focusedField = end;
                    // Native date segments can emit focus again while a focused field is cleared.
                    if (end === previous || !(end instanceof HTMLInputElement)
                        || end.form !== this.form || !end.name.endsWith('[ends_on]')) return;
                    this.updateEndDates();
                    if (!end.value && end.min && end.min <= end.max) {
                        end.value = end.min;
                        end.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                };
                this.form.addEventListener('input', this.refreshEndDates);
                this.form.addEventListener('change', this.refreshEndDates);
                document.addEventListener('focusin', this.suggestEndDate);
                this.updateEndDates();
            }

            disconnect() {
                this.observer.disconnect();
                this.form.removeEventListener('input', this.refreshEndDates);
                this.form.removeEventListener('change', this.refreshEndDates);
                document.removeEventListener('focusin', this.suggestEndDate);
            }

            dateString(day) {
                if (!Number.isFinite(day)) return '';
                const date = new Date(day).toISOString().slice(0, 10);
                return /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : '';
            }

            updateEndDates() {
                const seasonEnd = this.form.elements.namedItem('configuration[ends_on]');
                for (const end of this.form.querySelectorAll('input[name$="[ends_on]"]')) {
                    const start = this.form.elements.namedItem(end.name.replace('[ends_on]', '[starts_on]'));
                    end.min = this.dateString(start.valueAsNumber + 86400000);
                    end.max = end === seasonEnd ? '9998-12-31' : seasonEnd.value || '9998-12-31';
                }
            }

            defaultStart(row) {
                const start = row.querySelector('input[name$="[starts_on]"]');
                if (!start || start.value) return;

                const seasonStart = this.form.elements.namedItem('configuration[starts_on]');
                const seasonEnd = this.form.elements.namedItem('configuration[ends_on]');
                const previousEnd = row.previousElementSibling?.querySelector('input[name$="[ends_on]"]');
                // Date inputs expose UTC calendar days, independent of the browser's time zone or DST.
                const suggestedDay = previousEnd ? previousEnd.valueAsNumber + 86400000 : seasonStart.valueAsNumber;
                if (!Number.isFinite(suggestedDay)
                    || suggestedDay < seasonStart.valueAsNumber
                    || suggestedDay + 86400000 > seasonEnd.valueAsNumber) return;

                const suggestedDate = this.dateString(suggestedDay);
                if (!suggestedDate) return;
                start.value = suggestedDate;
                start.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    </script>
@endpush
