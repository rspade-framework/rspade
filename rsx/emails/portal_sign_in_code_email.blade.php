@rsx_id('Portal_Sign_In_Code_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Your sign-in code') !!}

<p>Hello,</p>

@if ($again)
<p>Here is your sign-in again - the same link and code as before. Use either one to finish signing in to the <strong>{{ $app_name }}</strong> client portal:</p>
@else
<p>Use this link or code to finish signing in to the <strong>{{ $app_name }}</strong> client portal:</p>
@endif

<p style="text-align: center;">
    <a href="{{ $link }}" class="email-button">Finish signing in</a>
</p>

<p style="font-size: 13px; color: #868e96; text-align: center;">
    The button works in the browser where you entered your password. On another device, type this code there instead:
</p>

<p style="text-align: center; font-size: 28px; font-weight: 600; letter-spacing: 6px;">{{ $code }}</p>

<p>The link and the code expire in {{ $expiry_minutes }} minutes and work once.</p>

<p style="font-size: 13px; color: #868e96;">
    If you did not just try to sign in, someone may know your password. Change it, and do not share this code or forward this email to anyone.
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
