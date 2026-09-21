@rsx_id('Errors_Forbidden')
@rsx_extends('Errors_Layout')

@section('title', 'Access Denied')

@section('content')
    <p class="mb-0">{{ $error->message }}</p>
@endsection

{{-- A denial reaching this page means the caller IS signed in (an anonymous visitor is
     sent to login instead), so the one thing they can act on is signing in as somebody
     who does have the permission. --}}
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ Rsx::Route('Login_Controller::logout') }}">Sign in as a different user</a>
@endsection
