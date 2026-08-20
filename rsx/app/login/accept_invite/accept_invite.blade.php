@rsx_id('Accept_Invite')
@rsx_extends('Login_Layout')

@section('title', 'Accept Invitation')

@if ($state === 'invalid' || $state === 'expired')
    @section('card_title', 'Invalid Invitation')
@elseif ($state === 'email_mismatch')
    @section('card_title', 'Email Mismatch')
    @section('card_subtitle', 'This invitation is for a different email address')
@elseif ($state === 'already_accepted')
    @section('card_title', 'Already Accepted')
@endif

@section('content')
    @if ($state === 'invalid' || $state === 'expired')
        <div class="alert alert-danger">
            {{ $message }}
        </div>
        <div class="mt-3 text-center">
            <a href="{{ Rsx::Route('Login_Controller') }}" class="btn btn-primary">Go to Login</a>
        </div>

    @elseif ($state === 'not_logged_in')
        {{-- Organization Badge --}}
        <div class="text-center mb-4">
            <div class="d-inline-flex flex-column align-items-center">
                {{-- Logo Placeholder --}}
                <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center mb-3"
                     style="width: 96px; height: 96px;">
                    <span class="text-primary fw-bold" style="font-size: 2.5rem;">
                        {{ strtoupper(substr($site_name, 0, 1)) }}
                    </span>
                </div>
                {{-- Organization Name --}}
                <h3 class="mb-2 fw-bold">{{ $site_name }}</h3>
                <p class="text-muted mb-0">has invited you to join their team</p>
            </div>
        </div>

        {{-- Invited Email --}}
        <div class="text-center mb-4">
            <small class="text-muted d-block mb-1">INVITED EMAIL</small>
            <div class="fw-semibold text-primary">{{ $invitation->email }}</div>
        </div>

        @if ($login_account_exists)
            {{-- Account exists - show sign in --}}
            <div class="d-grid mb-3">
                <a href="{{ Rsx::Route('Login_Controller::index', ['code' => $code]) }}" class="btn btn-primary btn-lg">
                    Sign In to Accept
                </a>
            </div>
            <div class="text-center">
                <small class="text-muted">
                    Not you? <a href="{{ Rsx::Route('Login_Controller::index', ['code' => $code, 'fill' => 'false']) }}">Use a different account</a>
                </small>
            </div>
        @else
            {{-- No account - show create account --}}
            <div class="d-grid mb-3">
                <a href="{{ Rsx::Route('Accept_Invite_Controller::create_account', ['code' => $code]) }}" class="btn btn-primary btn-lg">
                    Create Account
                </a>
            </div>
            <div class="text-center">
                <small class="text-muted">
                    Already have an account? <a href="{{ Rsx::Route('Login_Controller::index', ['code' => $code, 'fill' => 'false']) }}">Sign in instead</a>
                </small>
            </div>
        @endif

    @elseif ($state === 'logged_in')
        {{-- Organization Badge --}}
        <div class="text-center mb-4">
            <div class="d-inline-flex flex-column align-items-center">
                {{-- Logo Placeholder --}}
                <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center mb-3"
                     style="width: 96px; height: 96px;">
                    <span class="text-primary fw-bold" style="font-size: 2.5rem;">
                        {{ strtoupper(substr($site_name, 0, 1)) }}
                    </span>
                </div>
                {{-- Organization Name --}}
                <h3 class="mb-2 fw-bold">{{ $site_name }}</h3>
                <p class="text-muted mb-0">has invited you to join their team</p>
            </div>
        </div>

        {{-- Invited Email --}}
        <div class="text-center mb-4">
            <small class="text-muted d-block mb-1">INVITED EMAIL</small>
            <div class="fw-semibold text-primary">{{ $invitation->email }}</div>
        </div>

        <div class="d-grid">
            <button class="btn btn-primary btn-lg" id="accept-btn" data-code="{{ $code }}">
                Accept Invitation
            </button>
        </div>

    @elseif ($state === 'email_mismatch')
        <div class="alert alert-warning">
            <strong>This invitation was sent to:</strong><br>
            {{ $invited_email }}
        </div>
        <div class="alert alert-info">
            <strong>You are currently logged in as:</strong><br>
            {{ $current_email }}
        </div>
        <div style="margin-top: 30px; margin-bottom: 30px;">
            <p>To accept this invitation, you must be logged in with the invited email address. This security measure ensures invitations are accepted by the intended recipient.</p>
        </div>
        <div class="d-grid">
            <a href="{{ Rsx::Route('Login_Controller::logout', ['redirect' => request()->path()]) }}" class="btn btn-primary">
                Logout and Continue
            </a>
        </div>
        <div class="mt-3 text-center">
            <small class="text-muted">
                If you believe this is an error, contact your site administrator to request the invitation be updated or resent to your current email address.
            </small>
        </div>

    @elseif ($state === 'already_accepted')
        <div class="alert alert-info">
            You have already been added to {{ $site_name }}.
        </div>
        <div class="d-grid">
            <a href="{{ Rsx::Route('Site_Selection_Controller::select', $invitation->site_id) }}" class="btn btn-primary">
                Go to Account Dashboard
            </a>
        </div>
    @endif
@endsection
