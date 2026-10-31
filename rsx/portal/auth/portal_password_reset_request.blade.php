@rsx_id('Portal_Password_Reset_Request')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Reset Password')
@section('card_title', 'Reset Your Password')
@section('card_subtitle', 'Enter your email to receive a password reset link')

@section('content')
    @php
        $form_action = Rsx_Portal::Route('Portal_Password_Reset_Controller::request');
        $email_value = $posted_email ?? '';
    @endphp

    @if (isset($success) && $success)
        <div class="alert alert-success text-center">
            <strong>Check your email!</strong><br>
            If an account exists with that email address, you will receive a password reset link shortly.
        </div>

        <div class="mt-3 text-center">
            <small class="text-muted">
                <a href="{{ Rsx_Portal::Route('Portal_Login_Controller::index', Login_Redirect::params()) }}">Return to login</a>
            </small>
        </div>
    @else
        {{-- Display error if present --}}
        @if (isset($error) && $error)
            <div class="alert alert-danger text-center">
                {{ $error }}
            </div>
        @endif

        <form id="portal-password-reset-form" method="POST" action="{{ $form_action }}">
            @csrf
            {!! Login_Redirect::hidden_input() !!}

            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <input autofocus class="form-control" id="email" name="email" placeholder="your@email.com" required
                    type="email" value="{{ $email_value }}">
            </div>

            <div class="d-grid">
                <button class="btn btn-primary" id="btn-submit" type="submit">
                    Send Reset Link
                </button>
            </div>

            <div class="mt-3 text-center">
                <small class="text-muted">
                    Remember your password? <a href="{{ Rsx_Portal::Route('Portal_Login_Controller::index', Login_Redirect::params()) }}">Sign in</a>
                </small>
            </div>
        </form>
    @endif
@endsection

@section('below_card')
    @if (!(isset($success) && $success))
        <Turnstile_Input $form="portal-password-reset-form" />
    @endif
@endsection
