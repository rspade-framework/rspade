@rsx_id('Errors_Generic')
@rsx_extends('Errors_Layout')

@section('title', $error->title)

@section('content')
    <p class="mb-0">{{ $error->message }}</p>
    {{-- A redacted 500 carries the reference its detail was logged under, so a user's
         report can be matched to the trace. --}}
    @if ($error->error_id)
        <p class="Errors_Layout__reference">Reference: {{ $error->error_id }}</p>
    @endif
@endsection

{{-- The exception block, for a 500. No caller check here and none needed: the framework
     builds the context with detail = null unless the caller is a developer outside
     production, so the section simply has nothing to render for anybody else. An error
     page is inspectable with curl, and this is the reason the redaction lives server-side
     rather than in a template. --}}
@if ($error->detail)
    @section('detail')
        <p class="Errors_Layout__detail-label">{{ $error->detail['class'] }}</p>
        <p class="Errors_Layout__detail-message">{{ $error->detail['message'] }}</p>
        <p class="Errors_Layout__detail-origin">{{ $error->detail['file'] }}:{{ $error->detail['line'] }}</p>
        @if (!empty($error->detail['frames']))
            <pre class="Errors_Layout__trace">{{ implode("\n", $error->detail['frames']) }}</pre>
        @endif
    @endsection
@endif
