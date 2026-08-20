@rsx_id('Portal_Register_Invalid')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Registration Unavailable')
@section('card_title', 'Registration Unavailable')
@section('card_subtitle', 'There was a problem with your invitation')

@section('content')
    <div class="alert alert-danger text-center">
        {{ $error }}
    </div>

    @if (isset($show_login_link) && $show_login_link)
        <div class="d-grid">
            <a href="{{ Rsx_Portal::Route('Portal_Login_Controller::index') }}" class="btn btn-primary">
                Go to Login
            </a>
        </div>
    @else
        <div class="text-center">
            <small class="text-muted">
                If you believe this is an error, please contact support.
            </small>
        </div>
    @endif
@endsection
