@rsx_id('Portal_Password_Reset_Reset')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Set New Password')
@section('card_title', 'Set New Password')
@section('card_subtitle', 'Enter your new password below')

@section('content')
    @php
        $form_action = Rsx_Portal::Route('Portal_Password_Reset_Controller::reset', ['token' => $token]);
        $min_length = config('rsx.portal.password_min_length', 8);
    @endphp

    {{-- Show which account is being reset --}}
    <div class="alert alert-info">
        <small>Resetting password for: <strong>{{ $email }}</strong></small>
    </div>

    {{-- Display error if present --}}
    @if (isset($error) && $error)
        <div class="alert alert-danger text-center">
            {{ $error }}
        </div>
    @endif

    <form id="portal-password-reset-form" method="POST" action="{{ $form_action }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="password">New Password</label>
            <input autofocus class="form-control" id="password" name="password"
                placeholder="Enter new password (min {{ $min_length }} characters)" required type="password"
                minlength="{{ $min_length }}">
        </div>

        <div class="mb-3">
            <label class="form-label" for="password_confirm">Confirm New Password</label>
            <input class="form-control" id="password_confirm" name="password_confirm" placeholder="Confirm new password"
                required type="password" minlength="{{ $min_length }}">
        </div>

        <div class="d-grid">
            <button class="btn btn-primary" id="btn-submit" type="submit">
                Reset Password
            </button>
        </div>

        <div class="mt-3 text-center">
            <small class="text-muted">
                <a href="{{ Rsx_Portal::Route('Portal_Login_Controller::index') }}">Cancel and return to login</a>
            </small>
        </div>
    </form>
@endsection
