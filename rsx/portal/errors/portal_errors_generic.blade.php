@rsx_id('Portal_Errors_Generic')
@rsx_extends('Portal_Auth_Layout')

@section('title', $error->title)
@section('card_title', $error->title)

@section('content')
    <p class="text-body-secondary text-center">{{ $error->message }}</p>

    <div class="d-grid">
        <a class="btn btn-primary" href="{{ $error->home_url }}">Return to the Portal</a>
    </div>

    {{-- No exception block on a portal page, deliberately: the reader is a client, and
         the detail a developer needs is already in the log. The staff generic page is
         where the trace is rendered. --}}
    <p class="text-center text-body-secondary small mt-3 mb-0 font-monospace">ERROR {{ $error->status }}</p>
@endsection
