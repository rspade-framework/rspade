@rsx_id('Portal_Sign_In_Code_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Your sign-in code') !!}

<p><strong>Finish signing in to the {{ $app_name }} client portal</strong></p>

<p>Use the following code to sign in:</p>

<p style="text-align: center;">
    <span style="display: inline-block; padding: 16px 28px; font-family: 'Courier New', Courier, monospace; font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #1a202c; background-color: #f7fafc; border: 1px solid #d0d5dd; border-radius: 8px;">{{ $code }}</span>
</p>

<p>Or click the link below:</p>

<p><a href="{{ $link }}"><strong>Sign Into Client Portal</strong></a></p>

<p class="email-muted">Code and link expire in {{ $expiry_minutes }} {{ $expiry_minutes === 1 ? 'minute' : 'minutes' }}.</p>

<p class="email-muted">
    If you did not just try to sign in, someone may know your password. Change it, and do not share this code or forward this email to anyone.
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
