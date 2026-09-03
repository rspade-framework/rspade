@rsx_id('Login_Verify')
@rsx_extends('Login_Layout')

@section('title', 'Verify Your Sign-In')
@section('card_title', 'Two-Factor Verification')
@section('card_subtitle', 'Enter the code from your authenticator app')

@section('content')
    {{-- The whole screen is the framework component: it loads the pending challenge itself,
         offers the code box and the passkey button, posts to the endpoint named here, and
         follows the {redirect} that endpoint answers with. This page owns only the chrome.

         No <Turnstile_Input /> here, and none is wanted: the component posts {code} or
         {assertion} and nothing else, and the challenge is already gated by the pending
         state on this session plus the framework's brute-force budget. --}}
    <Two_Factor_Challenge $controller="Login_Controller" $method="verify_2fa" />

    <div class="mt-3 text-center">
        <small class="text-muted">
            Lost your device? Use one of your recovery codes above, or
            <a href="{{ Rsx::Route('Login_Controller::logout') }}">start over</a>.
        </small>
    </div>
@endsection
