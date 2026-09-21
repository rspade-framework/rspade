@rsx_id('Portal_Errors_Not_Found')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Page Not Found')
@section('card_title', $error->title)

@section('content')
    <p class="text-body-secondary text-center">{{ $error->message }}</p>

    <div class="d-grid">
        {{-- home_url is the PORTAL's home, computed by the framework from the realm:
             the portal prefix here, or the portal root on a dedicated domain. --}}
        <a class="btn btn-primary" href="{{ $error->home_url }}">Return to the Portal</a>
    </div>

    <p class="text-center text-body-secondary small mt-3 mb-0 font-monospace">ERROR {{ $error->status }}</p>
@endsection
