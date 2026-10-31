@rsx_id('Portal_Login_Index')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Login')
@section('card_title', 'Client Portal')
@section('card_subtitle', 'Please login to your account')

@section('content')
    @php
        $email_value = $posted_email ?? null;

        // NO credential auto-fill here, deliberately.
        //
        // The staff login offers it on a declared debug host, because those
        // credentials belong to the developer running the box. A portal account
        // belongs to a CLIENT - a different person, on a login page their users
        // reach - and the framework seeds no portal account at all, so there is
        // nothing it could honestly pre-fill.
        $has_error = isset($error) && $error;
        $default_password = '';

        // Build form action URL - preserve the invited-client destination across POST.
        $client_id = $client_id ?? 0;
        $form_action = Rsx_Portal::Route('Portal_Login_Controller::index', $client_id ? ['client' => $client_id] : []);
    @endphp

    {{-- The notice a flow sent the visitor here with (Login_Notice, rsx.login_notices). --}}
    @php $notice = Login_Notice::current(); @endphp
    @if ($notice)
        <div class="alert {{ $notice['level'] === 'error' ? 'alert-danger' : 'alert-info' }} text-center">
            {{ $notice['text'] }}
        </div>
    @endif

    {{-- Display error if present --}}
    @if (isset($error) && $error)
        <div class="alert alert-danger text-center">
            {{ $error }}
        </div>
    @endif

    <form id="portal-login-form" method="POST" action="{{ $form_action }}">
        @csrf
        {{-- Thread the intended-URL through the POST hop (portal-context aware). --}}
        {!! Login_Redirect::hidden_input() !!}

        <div class="mb-3">
            <label class="form-label" for="email">Email Address</label>
            <input autofocus class="form-control" id="email" name="email" placeholder="your@email.com" required
                type="email" value="{{ $email_value }}">
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input class="form-control" id="password" name="password" placeholder="Enter your password" required
                type="password" value="{{ $default_password }}">
        </div>

        <div class="d-grid">
            <button class="btn btn-primary" id="btn-submit" type="submit">
                Sign In
            </button>
        </div>

        {{-- THE OTHER WAYS IN. A passkey is always offered: <Passkey_Sign_In> starts a
             PORTAL passwordless ceremony on this page by itself and posts it to
             Portal_Login_Controller::passkey_login. Federated sign-in appears only when the
             portal realm has it switched on (rsx.sso.portal_enabled) and a provider is live -
             <Sso_Buttons> reads the PORTAL roster on a portal page, so these buttons start the
             portal's own ceremony, never the staff one. --}}
        <div class="login-alternatives">
            <div class="login-divider"><span>or</span></div>

            <Passkey_Sign_In $controller="Portal_Login_Controller" $method="passkey_login" />

            @if (Rsx_Portal_Sso::is_enabled())
                <div class="mt-2">
                    <Sso_Buttons />
                </div>
            @endif
        </div>

        <div class="mt-3 text-center">
            <small class="text-muted">
                <a href="{{ Rsx_Portal::Route('Portal_Password_Reset_Controller::request', Login_Redirect::params()) }}">Forgot your password?</a>
            </small>
        </div>

        <div class="mt-2 text-center">
            <small class="text-muted">
                Portal access is by invitation. To be invited, contact {{ config('rsx.name', 'us') }}.
            </small>
        </div>
    </form>
@endsection

@section('below_card')
    <Turnstile_Input $form="portal-login-form" />
@endsection
