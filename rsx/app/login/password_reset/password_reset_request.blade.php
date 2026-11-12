@rsx_id('Password_Reset_Request')
@rsx_extends('Login_Layout')

@section('title', 'Reset Password')
@section('card_title', 'Reset Your Password')
@section('card_subtitle', 'Enter your email to receive a password reset link')

@section('content')
    @if ($sent)
        {{-- The same sentence for every address: whether one holds an account is never said. --}}
        <div class="alert alert-success text-center">
            <strong>Check your email.</strong><br>
            If an account exists for that address, a link to choose a new password is on its way.
        </div>

        <div class="mt-3 text-center">
            <small class="text-muted"><a href="{{ Rsx::Route('Login_Controller::index') }}">Return to sign in</a></small>
        </div>
    @else
        @if ($error)
            <div class="alert alert-danger text-center">{{ $error }}</div>
        @endif

        <form id="password-reset-form" method="POST" action="{{ Rsx::Route('Password_Reset_Controller::request') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <input autocomplete="username" autofocus class="form-control" id="email" name="email" placeholder="you@example.com" required
                    type="email" value="{{ $posted_email }}">
            </div>

            <div class="d-grid">
                <button class="btn btn-primary" id="btn-submit" type="submit">Send Reset Link</button>
            </div>

            <div class="mt-3 text-center">
                <small class="text-muted">Remember your password? <a href="{{ Rsx::Route('Login_Controller::index') }}">Sign in</a></small>
            </div>
        </form>
    @endif
@endsection

@section('below_card')
    @if (!$sent)
        <Turnstile_Input $form="password-reset-form" />
    @endif
@endsection
