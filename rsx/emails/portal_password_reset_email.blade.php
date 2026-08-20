@rsx_id('Portal_Password_Reset_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Password Reset') !!}

<p>Hello,</p>

<p>We received a request to reset your password for the <strong>{{ $app_name }}</strong> client portal.</p>

<p>Click the button below to set a new password:</p>

<p style="text-align: center;">
    <a href="{{ $reset_url }}" class="email-button">Reset Password</a>
</p>

<p>This link expires in {{ $expiry_hours }} hour{{ $expiry_hours != 1 ? 's' : '' }}.</p>

<p style="font-size: 13px; color: #868e96;">
    If you did not request a password reset, you can safely ignore this email. Your password will not be changed.
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
