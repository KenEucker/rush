<style>
    .matrix th:has(input[type="hidden"]) { display: none; }
    .matrix .ts-wrapper { min-width: 10rem; }
    .matrix .ts-control .item { overflow-wrap: anywhere; }
    @media (max-width: 767px) {
        .matrix, .matrix tbody, .matrix tr, .matrix th { display: block; width: 100%; }
        .matrix thead { display: none; }
        .matrix tr:not(.add-row) { padding: .5rem; margin-bottom: 1rem; border: 1px solid #d5d9dd; }
        .matrix th:has(input[type="hidden"]) { display: none; }
        .matrix a { min-height: 44px; display: inline-flex; align-items: center; }
        .matrix th:has(input, select)::before { display: block; padding: .3rem .75rem 0; font-weight: normal; }
        .matrix th:has(input[aria-label="Shift name"])::before { content: 'Shift name'; }
        .matrix th:has(input[aria-label="Shift local start"])::before { content: 'Local start'; }
        .matrix th:has(input[aria-label="Shift duration in minutes"])::before { content: 'Duration (minutes)'; }
        .matrix th:has(input[aria-label="Area name"])::before { content: 'Area name'; }
        .matrix th:has(input[aria-label="Group name"])::before { content: 'Group name'; }
        .matrix th:has(select[aria-label="Group area names"])::before { content: 'Area names'; }
        .matrix th:has(select[aria-label="Staffing shift name"])::before { content: 'Shift name'; }
        .matrix th:has(select[aria-label="Staffing dedicated area"])::before { content: 'Dedicated area'; }
        .matrix th:has(select[aria-label="Staffing shared group"])::before { content: 'Shared group'; }
        .matrix th:has(input[aria-label="Required positions"])::before { content: 'Positions'; }
    }
</style>
<div class="bg-white rounded p-4 mb-3" data-controller="coverage-references">
    <h2 class="h5">{{ $season->name }} · {{ $phase->name }}</h2>
    <p>{{ \Carbon\CarbonImmutable::parse($phase->starts_on, 'UTC')->format('D, M j, Y') }}
        through {{ \Carbon\CarbonImmutable::parse($phase->ends_on, 'UTC')->format('D, M j, Y') }} · {{ $season->timezone }}</p>
    <a href="{{ route('platform.seasons', [$organization->id, $season->id]) }}">Back to season and phases</a>
    · <a href="{{ route('platform.phase-policies', [$organization->id, $phase->id]) }}">Configure scheduling policies</a>
    <p class="mt-3 mb-0">Changes configure future planning inputs. Existing assignment times and their saved area context stay intact. Partial assignments are recorded separately from whole shift definitions.</p>
</div>

@push('scripts')
    <script>
        window.application.register('coverage-references', class extends window.Controller {
            connect() {
                this.form = this.element.closest('form');
                this.onReferenceInput = (event) => {
                    if (event.target.matches('input[name$="[name]"], select')) {
                        this.refreshReferences();
                    }
                };
                this.form.addEventListener('input', this.onReferenceInput);
                this.form.addEventListener('change', this.onReferenceInput);
                // Matrix rows and Orchid's select widgets connect independently, including after Turbo navigation.
                this.observer = new MutationObserver((changes) => {
                    if (changes.some((change) => [...change.addedNodes, ...change.removedNodes].some((node) =>
                        node instanceof Element && (node.matches('tr, .ts-wrapper'))))) this.refreshReferences();
                });
                this.observer.observe(this.form, { childList: true, subtree: true });
                this.refreshReferences();
            }

            disconnect() {
                this.observer.disconnect();
                this.form.removeEventListener('input', this.onReferenceInput);
                this.form.removeEventListener('change', this.onReferenceInput);
            }

            refreshReferences() {
                const references = [
                    ['areas', 'Group area names', ''],
                    ['shifts', 'Staffing shift name', 'Choose a shift'],
                    ['areas', 'Staffing dedicated area', 'No dedicated area'],
                    ['groups', 'Staffing shared group', 'No shared group'],
                ];
                for (const [collection, label, emptyLabel] of references) {
                    const names = [...new Set([...this.form.querySelectorAll(`input[name^="configuration[${collection}]"][name$="[name]"]`)]
                        .map((input) => input.value.trim()).filter(Boolean))];
                    for (const select of this.form.querySelectorAll(`select[aria-label="${label}"]`)) {
                        const selected = [...select.selectedOptions].map((option) => option.value).filter(Boolean);
                        const values = [...new Set([...names, ...selected])];
                        const options = values.map((value) => ({ value, text: names.includes(value) ? value : `${value} (not defined)` }));
                        if (!select.multiple) options.unshift({ value: '', text: emptyLabel });
                        const choices = select.tomselect;
                        if (choices) {
                            choices.control_input.setAttribute('aria-label', label);
                            for (const value of Object.keys(choices.options)) {
                                if (!options.some((option) => option.value === value)) choices.removeOption(value, true);
                            }
                            for (const option of options) {
                                if (!choices.options[option.value]) choices.addOption(option);
                                else if (choices.options[option.value].text !== option.text) choices.updateOption(option.value, option);
                            }
                            choices.refreshOptions(false);
                        } else {
                            select.replaceChildren(...options.map(({ value, text }) => new Option(text, value, false, selected.includes(value))));
                            if (!select.multiple && selected.length === 0) select.value = '';
                        }
                    }
                }
            }
        });
    </script>
@endpush
