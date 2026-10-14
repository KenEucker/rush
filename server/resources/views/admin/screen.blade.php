@extends('platform::layouts.base')

@section('content')
    @php($managementYear = app(\App\Orchid\ManagementYear::class))
    @php($yearContext = $managementYear->context())
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
