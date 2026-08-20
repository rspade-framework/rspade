@rsx_id('Portal_Request_Access')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Request Portal Access')
@section('card_title', 'Request Portal Access')

@section('content')
    @php
        $form_action = Rsx_Portal::Route('Portal_Request_Access_Controller::index');
        $login_url = Rsx_Portal::Route('Portal_Login_Controller::index');
        $email_value = $posted_email ?? '';
    @endphp

    @if ($outcome === 'invited')
        <div class="alert alert-success text-center">
            <strong>Invitation sent.</strong><br>
            We've emailed your portal invitation to {{ $posted_email }}. Check your inbox to finish setting up your account.
        </div>
        <div class="mt-3 text-center">
            <small class="text-muted">
                <a href="{{ $login_url }}">Return to sign in</a>
            </small>
        </div>
    @elseif ($outcome === 'exists')
        <div class="alert alert-info text-center">
            <strong>You already have a portal account.</strong><br>
            Sign in with your email and password.
        </div>
        <div class="d-grid">
            <a class="btn btn-primary" href="{{ $login_url }}">Sign in</a>
        </div>
    @elseif ($outcome === 'not_found')
        <div class="alert alert-warning text-center">
            We couldn't find a {{ $brand }} portal account or invitation for <strong>{{ $posted_email }}</strong>.
            Please contact {{ $brand }} to be added to the client portal.
        </div>
        <div class="mt-3 text-center">
            <small class="text-muted">
                <a href="{{ $login_url }}">Return to sign in</a>
            </small>
        </div>
    @else
        @if (isset($error) && $error)
            <div class="alert alert-danger text-center">{{ $error }}</div>
        @endif

        <p class="text-muted text-center small mb-3">
            Enter the email address associated with your {{ $brand }} account and we'll send your portal invitation.
        </p>

        <form method="POST" action="{{ $form_action }}">
            @csrf

            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <input autofocus class="form-control" id="email" name="email" placeholder="your@email.com" required
                    type="email" value="{{ $email_value }}">
            </div>

            <Turnstile_Input />

            <div class="d-grid">
                <button class="btn btn-primary" type="submit">Continue</button>
            </div>

            <div class="mt-3 text-center">
                <small class="text-muted">
                    Already have an account? <a href="{{ $login_url }}">Sign in</a>
                </small>
            </div>
        </form>
    @endif
@endsection
