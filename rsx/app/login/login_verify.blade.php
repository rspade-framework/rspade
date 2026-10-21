@rsx_id('Login_Verify')
@rsx_extends('Login_Layout')

@section('title', $is_address_verification ? 'Verify Your Email' : 'Verify Your Sign-In')
@section('card_title', $is_address_verification ? 'Verify Your Email' : 'Two-Factor Verification')
@section('card_subtitle', $has_issued_code ? 'Enter the code we emailed you, or open the link in that email' : 'Enter the code from your authenticator app')

@section('content')
    {{-- The whole screen is the framework component: it loads the pending challenge itself,
         offers the code box and the passkey button, posts to the endpoint named here, and
         follows the {redirect} that endpoint answers with. $cancel_url adds Cancel, which
         discards the pending challenge and returns to the sign-in form. This page owns only
         the chrome.

         The send endpoint is named only when the challenge accepts an emailed code - an
         address verification: a self-signup, or an unverified address signing in. The
         component then sends the first code itself and offers "Resend code". The email carries the code AND a sign-in link; the link
         brings this browser back here and the component submits it.

         No <Turnstile_Input /> here, and none is wanted: the component posts {code},
         {assertion} or {email_link} and nothing else, and the challenge is already gated by
         the pending state on this session plus the framework's brute-force budget. --}}
    @if ($has_issued_code)
        <Two_Factor_Challenge $controller="Login_Controller" $method="verify_2fa"
                              $send_controller="Login_Controller" $send_method="send_code"
                              $cancel_url="{{ Rsx::Route('Login_Controller::index') }}" />
    @else
        <Two_Factor_Challenge $controller="Login_Controller" $method="verify_2fa"
                              $cancel_url="{{ Rsx::Route('Login_Controller::index') }}" />
    @endif

    @if ($has_recovery_codes)
        {{-- Hidden by login_verify.js once the component says the sign-in completed. --}}
        <div class="Login_Verify__footer mt-3 text-center">
            <small class="text-muted">Lost your device? Enter one of your recovery codes instead.</small>
        </div>
    @endif
@endsection
