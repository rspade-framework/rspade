@rsx_id('Two_Factor_Challenge_Spent_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Sign-in attempts were blocked') !!}

<p>Hello,</p>

<p>
    Someone entered the correct password for <strong>{{ $email }}</strong> on
    {{ $app_name }}{{ $is_portal ? ' (client portal)' : '' }}, and then entered an incorrect verification code
    several times. The sign-in was stopped and nobody was signed in.
</p>

<p><strong>If this was you</strong>, nothing is wrong: sign in again and enter the current code.</p>

<p>
    <strong>If this was not you</strong>, somebody knows your password. Change it now, and tell your
    administrator.
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
