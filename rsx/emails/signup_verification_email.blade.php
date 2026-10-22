@rsx_id('Signup_Verification_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Confirm your email') !!}

<p><strong>Confirm your email address for {{ $app_name }}</strong></p>

<p>Use the following code to confirm it:</p>

<p style="text-align: center;">
    <span style="display: inline-block; padding: 16px 28px; font-family: 'Courier New', Courier, monospace; font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #1a202c; background-color: #f7fafc; border: 1px solid #d0d5dd; border-radius: 8px;">{{ $code }}</span>
</p>

<p>Or click the link below:</p>

<p><a href="{{ $link }}"><strong>Confirm My Email</strong></a></p>

<p class="email-muted">Code and link expire in {{ $expiry_minutes }} {{ $expiry_minutes === 1 ? 'minute' : 'minutes' }}.</p>

<p class="email-muted">If you did not just sign up or sign in, you can ignore this email.</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
