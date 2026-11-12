@rsx_id('Portal_Login_New_Password')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Choose a New Password')
@section('card_title', 'Choose a New Password')
@section('card_subtitle', 'Your password must be changed before you can sign in')

@section('content')
    <div class="alert alert-info">
        <small>Signing in as <strong>{{ $email }}</strong>. Choose a new password to continue.</small>
    </div>

    @if ($error)
        <div class="alert alert-danger text-center">{{ $error }}</div>
    @endif

    <form id="portal-new-password-form" method="POST" action="{{ Rsx_Portal::Route('Portal_Login_Controller::new_password') }}">
        @csrf

        {{-- Whose password this is, for a password manager (CREDENTIAL FIELDS). No name: the
             account is the one parked on this browser's session, never this field. --}}
        <input autocomplete="username" hidden id="email" readonly type="email" value="{{ $email }}">

        <div class="mb-3">
            <label class="form-label" for="new_password">New Password</label>
            <input autocomplete="new-password" autofocus class="form-control" id="new_password" name="password"
                placeholder="At least {{ $min_length }} characters" required type="password">
        </div>

        <div class="mb-3">
            <label class="form-label" for="new_password_confirmation">Confirm New Password</label>
            <input autocomplete="new-password" class="form-control" id="new_password_confirmation" name="password_confirmation"
                placeholder="Type it again" required type="password">
        </div>

        <div class="d-grid">
            <button class="btn btn-primary" id="btn-submit" type="submit">Set Password and Continue</button>
        </div>

        <div class="mt-3 text-center">
            <small class="text-muted"><a href="{{ Rsx_Portal::Route('Portal_Login_Controller::new_password', ['cancel' => 1]) }}">Cancel and return to sign in</a></small>
        </div>
    </form>
@endsection
