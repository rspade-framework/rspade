@rsx_id('Portal_Password_Reset_Invalid')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Password Reset Unavailable')
@section('card_title', 'Password Reset Unavailable')
@section('card_subtitle', 'There was a problem with your reset link')

@section('content')
    <div class="alert alert-danger text-center">
        {{ $error }}
    </div>

    <div class="d-grid gap-2">
        @if (isset($show_request_link) && $show_request_link)
            <a href="{{ Rsx_Portal::Route('Portal_Password_Reset_Controller::request') }}" class="btn btn-primary">
                Request New Reset Link
            </a>
        @endif

        @if (isset($show_login_link) && $show_login_link)
            <a href="{{ Rsx_Portal::Route('Portal_Login_Controller::index') }}" class="btn btn-secondary">
                Go to Login
            </a>
        @endif
    </div>

    @if (!isset($show_request_link) && !isset($show_login_link))
        <div class="text-center">
            <small class="text-muted">
                If you believe this is an error, please contact support.
            </small>
        </div>
    @endif
@endsection
