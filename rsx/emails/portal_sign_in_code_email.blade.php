@rsx_id('Portal_Sign_In_Code_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Your sign-in code') !!}

<p>Hello,</p>

<p>Use this code to finish signing in to the <strong>{{ $app_name }}</strong> client portal:</p>

<p style="text-align: center; font-size: 28px; font-weight: 600; letter-spacing: 6px;">{{ $code }}</p>

<p>The code expires in {{ $expiry_minutes }} minutes and works once.</p>

<p style="font-size: 13px; color: #868e96;">
    If you did not just try to sign in, someone may know your password. Change it, and do not share this code with anyone.
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
