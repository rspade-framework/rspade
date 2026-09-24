@rsx_id('Portal_Login_Verify')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Verify Your Sign-In')
@section('card_title', 'Two-Factor Verification')
@section('card_subtitle', 'Confirm it is you')

@section('content')
    {{-- The whole screen is the framework component. On a portal page it talks to the portal
         realm's controller by itself (Rsx_Two_Factor.controller()), loads the pending
         challenge, offers the code box and the passkey button, posts to the endpoint named
         here and follows the {redirect} it answers with. This page owns only the chrome.
         No Turnstile: the component posts {code} or {assertion} and nothing else. --}}
    <Two_Factor_Challenge $controller="Portal_Login_Controller" $method="verify_2fa" />

    <div class="mt-3 text-center">
        <small class="text-muted">
            Lost your device? Use one of your recovery codes above, or
            <a href="{{ Rsx_Portal::Route('Portal_Login_Controller::index') }}">start over</a>.
        </small>
    </div>
@endsection
