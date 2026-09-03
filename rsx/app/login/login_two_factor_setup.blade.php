@rsx_id('Login_Two_Factor_Setup')
@rsx_extends('Login_Layout')

@section('title', 'Set Up Two-Factor Authentication')
@section('card_title', 'Two-Factor Setup Required')
@section('card_subtitle', 'An administrator requires two-factor authentication on this account')

@section('content')
    <div class="Login_Two_Factor_Setup">
        <p class="text-muted">
            Choose how you want to verify your sign-ins. You can add the other method later
            from Settings.
        </p>

        {{-- The chooser. The chosen enrollment component is mounted into the target below by
             login_two_factor_setup.js, which is also what listens for the completion event -
             mounting from JS is what gives the page a handle on the instance. --}}
        <div class="d-grid gap-2 Login_Two_Factor_Setup__choices">
            <button type="button" class="btn btn-primary" data-tfa-choice="totp">
                Authenticator App
            </button>
            <button type="button" class="btn btn-outline-secondary" data-tfa-choice="passkey">
                Passkey
            </button>
        </div>

        <div class="Login_Two_Factor_Setup__mount mt-3"></div>

        <div class="mt-3 text-center">
            <small class="text-muted">
                Not you? <a href="{{ Rsx::Route('Login_Controller::logout') }}">Sign out</a>.
            </small>
        </div>
    </div>
@endsection
