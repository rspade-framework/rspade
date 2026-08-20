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

    {{-- Display message based on query parameter --}}
    @if (isset($message) && $message === 'logged_out')
        <div class="alert alert-info text-center">
            You have been logged out successfully.
        </div>
    @elseif (isset($message) && $message === 'account_exists')
        <div class="alert alert-info text-center">
            You already have an account for this email. Please sign in below, or use
            <a href="{{ Rsx_Portal::Route('Portal_Password_Reset_Controller::request') }}">Forgot your password?</a>
            if you don't remember it.
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

        <Turnstile_Input />

        <div class="d-grid">
            <button class="btn btn-primary" id="btn-submit" type="submit">
                Sign In
            </button>
        </div>

        <div class="mt-3 text-center">
            <small class="text-muted">
                <a href="{{ Rsx_Portal::Route('Portal_Password_Reset_Controller::request') }}">Forgot your password?</a>
            </small>
        </div>

        <div class="mt-2 text-center">
            <small class="text-muted">
                Don't have a portal account?
                <a href="{{ Rsx_Portal::Route('Portal_Request_Access_Controller::index') }}">Create one now</a>
            </small>
        </div>
    </form>
@endsection
