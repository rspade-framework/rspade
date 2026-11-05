@rsx_id('Portal_Register_Index')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Create Account')
@section('card_title', 'Create Your Account')
@section('card_subtitle', 'Set your password to complete registration')

@section('content')
    @php
        $form_action = Rsx_Portal::Route('Portal_Register_Controller::index');
        $min_length = config('rsx.portal.password_min_length', 8);
    @endphp

    {{-- Show invitation email --}}
    <div class="alert alert-info">
        <small>Creating account for: <strong>{{ $invitation->email }}</strong></small>
    </div>

    {{-- Display error if present --}}
    @if (isset($error) && $error)
        <div class="alert alert-danger text-center">
            {{ $error }}
        </div>
    @endif

    <form id="portal-register-form" method="POST" action="{{ $form_action }}">
        @csrf
        {{-- Thread the intended-URL through the POST hop (portal-context aware). --}}
        {!! Login_Redirect::hidden_input() !!}
        <input type="hidden" name="code" value="{{ $invitation->invitation_code }}">

        {{-- The account this password belongs to, for a password manager to save it under
             (Credential fields, rsx/portal/auth/CLAUDE.md). It has no name, so it is never
             posted: the account comes from the invitation, not from this field. --}}
        <input autocomplete="username" hidden id="email" readonly type="email" value="{{ $invitation->email }}">

        <div class="mb-3">
            <label class="form-label" for="new_password">Password</label>
            <input autocomplete="new-password" autofocus class="form-control" id="new_password" name="password"
                placeholder="Create a password (min {{ $min_length }} characters)" required type="password"
                minlength="{{ $min_length }}">
        </div>

        <div class="mb-3">
            <label class="form-label" for="new_password_confirmation">Confirm Password</label>
            <input autocomplete="new-password" class="form-control" id="new_password_confirmation" name="password_confirm" placeholder="Confirm your password"
                required type="password" minlength="{{ $min_length }}">
        </div>

        <div class="d-grid">
            <button class="btn btn-primary" id="btn-submit" type="submit">
                Create Account
            </button>
        </div>

        <div class="mt-3 text-center">
            <small class="text-muted">
                Already have an account? <a href="{{ Rsx_Portal::Route('Portal_Login_Controller::index', Login_Redirect::params()) }}">Sign in</a>
            </small>
        </div>
    </form>
@endsection

@section('below_card')
    <Turnstile_Input $form="portal-register-form" />
@endsection
