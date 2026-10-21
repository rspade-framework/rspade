@rsx_id('Signup_Verification_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Confirm your email') !!}

<p>Hello,</p>

@if ($again)
<p>Here is your confirmation again - the same link and code as before. Use either one to confirm this address and continue to your <strong>{{ $app_name }}</strong> account:</p>
@else
<p>Confirm this address to continue to your <strong>{{ $app_name }}</strong> account:</p>
@endif

<p style="text-align: center;">
    <a href="{{ $link }}" class="email-button">Confirm my email</a>
</p>

<p style="font-size: 13px; color: #868e96; text-align: center;">
    The button works in the browser where you signed up or signed in. On another device, type this code there instead:
</p>

<p style="text-align: center; font-size: 28px; font-weight: 600; letter-spacing: 6px;">{{ $code }}</p>

<p>The link and the code expire in {{ $expiry_minutes }} minutes and work once.</p>

<p style="font-size: 13px; color: #868e96;">
    If you did not just sign up or sign in, you can ignore this email.
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
