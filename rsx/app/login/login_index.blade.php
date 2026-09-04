@rsx_id('Login_Index')
@rsx_extends('Login_Layout')

@section('title', 'Login')
@section('card_title', 'Welcome Back')
@section('card_subtitle', 'Please login to your account')

@section('content')
    @php
        // Check for invite code prefill (takes priority over dev auto-fill)
        $email_value = $prefill_email ?? null;

        // Developer credential auto-fill. TWO conditions, both required:
        //   1. RSPADE_LOGIN_AUTOFILL is on - the developer asked for it, in the
        //      first-run wizard or by hand. It is off unless someone said so.
        //   2. Credentials are actually configured.
        // Credentials existing is NOT enough: RSPADE_DEFAULT_* are also the pair the
        // first user was created with, so they are set on installs that never wanted
        // a pre-filled form.
        $autofill_on = (bool) config('rsx.development.login_autofill', false);
        $dev_email = $autofill_on ? trim((string) config('rsx.default_user.email', '')) : '';
        $dev_password = $autofill_on ? (string) config('rsx.default_user.password', '') : '';
        $can_autofill = $dev_email !== '' && $dev_password !== '';

        if (!$email_value && $can_autofill) {
            $email_value = $dev_email;
        }

        $has_error = isset($error) && $error;
        $default_password = ($can_autofill && !$has_error && !isset($invite_code)) ? $dev_password : '';

        // Check for message query parameter
        $message = request()->query('message');

        // Build form action URL with code parameter if present
        $form_action_params = [];
        if (isset($invite_code) && $invite_code) {
            $form_action_params['code'] = $invite_code;
        }
        $form_action = Rsx::Route('Login_Controller::index', $form_action_params);
    @endphp

    {{-- Display message based on query parameter --}}
    @if ($message === 'logged_out')
        <div class="alert alert-info text-center">
            You have been logged out successfully.
        </div>
    @endif

    {{-- Display reason for logout --}}
    @php
        $reason = request()->query('reason');
    @endphp
    @if ($reason === 'unauthorized')
        <div class="alert alert-danger text-center">
            <strong>Your account is inactive, you have been logged out.</strong>
        </div>
    @endif

    {{-- Display error if present --}}
    @if (isset($error) && $error)
        <div class="alert alert-danger text-center">
            {{ $error }}
        </div>
    @endif

    {{-- Developer auto-fill is active: say so, and say how to stop it. A form that
         arrives already filled in should never leave anyone wondering where the
         credentials came from, or how to make it stop on a host that turns out to
         be more reachable than they assumed. --}}
    @if ($can_autofill)
        <div class="alert alert-warning small">
            <strong>These credentials were filled in for you.</strong>
            <code>RSPADE_LOGIN_AUTOFILL</code> is on in your <code>.env</code>, a
            development convenience. Clear that value to turn it off - and do turn it
            off if anyone else can reach this address.
        </div>
    @endif

    <form id="login-form" method="POST" action="{{ $form_action }}">
        @csrf

        @if (isset($invite_code) && $invite_code)
            <input type="hidden" name="code" value="{{ $invite_code }}">
        @endif

        <div class="mb-3">
            <label class="form-label" for="email">Email Address</label>
            <input autofocus class="form-control" id="email" name="email" placeholder="admin@test.com" required
                type="email" value="{{ $email_value }}">
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input class="form-control" id="password" name="password" placeholder="Enter your password" required
                type="password" value="{{ $default_password }}">
        </div>

        <Turnstile_Input />

        <div class="d-grid">
            <button class="btn btn-primary" id="btn-submit" type="submit">
                Sign In
            </button>
        </div>

        {{-- FEDERATED SIGN-IN. The divider is THIS page's, not the component's: the
             buttons component renders nothing at all when no provider is switched on,
             so a page that wants an "or" rule above them has to ask the same question
             the component asks - Rsx_Sso::is_enabled(). Both halves sit inside one @if
             for exactly that reason. --}}
        @if (Rsx_Sso::is_enabled())
            <div class="login-divider"><span>or</span></div>

            <Sso_Buttons />
        @endif

        <div class="mt-3 text-center">
            <small class="text-muted">
                Don't have an account? <a href="{{ Rsx::Route('Signup_Controller') }}">Sign up</a>
            </small>
        </div>
    </form>
@endsection
