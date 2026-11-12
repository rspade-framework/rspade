@rsx_id('Password_Reset_Reset')
@rsx_extends('Login_Layout')

@section('title', 'Choose a New Password')
@section('card_title', 'Choose a New Password')
@section('card_subtitle', 'Then sign in with it')

@section('content')
    <div class="alert alert-info">
        <small>Choosing a new password for <strong>{{ $email }}</strong></small>
    </div>

    @if ($error)
        <div class="alert alert-danger text-center">{{ $error }}</div>
    @endif

    <form id="password-reset-form" method="POST" action="{{ Rsx::Route('Password_Reset_Controller::reset', ['token' => $token]) }}">
        @csrf

        {{-- Whose password this is, for a password manager to save it under (CREDENTIAL FIELDS,
             rsx/app/login/CLAUDE.md). No name: never posted - the account comes from the link. --}}
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
            <button class="btn btn-primary" id="btn-submit" type="submit">Set Password</button>
        </div>

        <div class="mt-3 text-center">
            <small class="text-muted"><a href="{{ Rsx::Route('Login_Controller::index') }}">Cancel and return to sign in</a></small>
        </div>
    </form>
@endsection
