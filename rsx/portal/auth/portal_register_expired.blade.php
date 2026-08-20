@rsx_id('Portal_Register_Expired')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Invitation Expired')
@section('card_title', 'Invitation Expired')
@section('card_subtitle', 'Your invitation link is no longer active')

@section('content')
    <div class="alert alert-warning text-center">
        This invitation has expired. You can still create an account with your email
        address below, though an administrator will need to grant you client access.
    </div>

    <div class="d-grid">
        <a href="{{ Rsx_Portal::Route('Portal_Register_Controller::index', ['code' => $code, 'allow_expired' => 1]) }}"
            class="btn btn-primary">
            Create Account
        </a>
    </div>

    <div class="mt-3 text-center">
        <small class="text-muted">
            Already have an account? <a href="{{ Rsx_Portal::Route('Portal_Login_Controller::index') }}">Sign in</a>
        </small>
    </div>
@endsection
