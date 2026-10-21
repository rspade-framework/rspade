@rsx_id('Portal_Login_Verify')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Verify Your Sign-In')
@section('card_title', 'Two-Factor Verification')
@section('card_subtitle', $has_issued_code ? 'Enter the code we emailed you, or open the link in that email' : 'Confirm it is you')

@section('content')
    {{-- The whole screen is the framework component. On a portal page it talks to the portal
         realm's controller by itself (Rsx_Two_Factor.controller()), loads the pending
         challenge, offers the code box and the passkey button, posts to the endpoint named
         here and follows the {redirect} it answers with; $cancel_url adds Cancel (discard the
         challenge, back to the sign-in form). The send endpoint is used only when the
         challenge accepts an emailed code (rsx.portal.emailed_sign_in_codes): the component
         then sends the first code itself and offers "Resend code". The email carries the code
         AND a sign-in link, which brings this browser back here for the component to submit.
         This page owns only the chrome.
         No Turnstile: the component posts {code} or {assertion} and nothing else. --}}
    <Two_Factor_Challenge $controller="Portal_Login_Controller" $method="verify_2fa"
                          $send_controller="Portal_Login_Controller" $send_method="send_code"
                          $cancel_url="{{ Rsx_Portal::Route('Portal_Login_Controller::index') }}" />

    @if ($has_recovery_codes)
        {{-- Hidden by portal_login_verify.js once the component says the sign-in completed
             (the email link, or a code typed in another tab of this browser). --}}
        <div class="Portal_Login_Verify__footer mt-3 text-center">
            <small class="text-muted">Lost your device? Enter one of your recovery codes instead.</small>
        </div>
    @endif
@endsection
