@extends('platform::layouts.base')

@section('content')
    @php($managementYear = app(\App\Orchid\ManagementYear::class))
    @php($yearContext = $managementYear->context())
    <form method="get" action="{{ route('platform.main') }}" class="bg-white rounded p-3 mb-3 d-flex flex-wrap align-items-end gap-3">
        <div>
            <label for="management-year" class="form-label">Management year</label>
            <select id="management-year" name="year" class="form-select">
                @foreach($managementYear->years() as $year)
                    <option value="{{ $year }}" @selected($year === $yearContext['year'])>{{ $year }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">View year</button>
        <p class="mb-0">Seasons are grouped by their starting year.</p>
    </form>
    @if($yearContext['readOnly'])
        <p role="status" class="alert alert-info">{{ $yearContext['year'] }} · Read-only archive. Past-year seasons and their configuration cannot be changed.</p>
        <style>
            .management-archive .matrix .add-row, .management-archive .matrix a { display: none !important; }
            .management-archive :disabled { color: #212529; -webkit-text-fill-color: #212529; opacity: 1; }
            .management-archive .ts-control { pointer-events: none; }
        </style>
    @endif
    <fieldset @disabled($yearContext['readOnly']) class="{{ $yearContext['readOnly'] ? 'management-archive' : '' }}">
        @parent
    </fieldset>
@endsection
