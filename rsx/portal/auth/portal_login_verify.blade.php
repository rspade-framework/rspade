@rsx_id('Portal_Login_Verify')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Verify Your Sign-In')
@section('card_title', 'Two-Factor Verification')
@section('card_subtitle', 'Confirm it is you')

@section('content')
    {{-- The whole screen is the framework component. On a portal page it talks to the portal
         realm's controller by itself (Rsx_Two_Factor.controller()), loads the pending
         challenge, offers the code box and the passkey button, posts to the endpoint named
         here and follows the {redirect} it answers with; $cancel_url adds Cancel (discard the
         challenge, back to the sign-in form). The send endpoint is used only when the
         challenge accepts an emailed code (rsx.portal.emailed_sign_in_codes): the component
         then sends the first code itself and offers "Send a new code". This page owns only
         the chrome.
         No Turnstile: the component posts {code} or {assertion} and nothing else. --}}
    <Two_Factor_Challenge $controller="Portal_Login_Controller" $method="verify_2fa"
                          $send_controller="Portal_Login_Controller" $send_method="send_code"
                          $cancel_url="{{ Rsx_Portal::Route('Portal_Login_Controller::index') }}" />

    <div class="mt-3 text-center">
        <small class="text-muted">Lost your device? Enter one of your recovery codes instead.</small>
    </div>
@endsection
