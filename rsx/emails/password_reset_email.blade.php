@rsx_id('Password_Reset_Email')
{!! \App\RSpade\Lib\Messaging\Mail\Rsx_Mail_Layout::header($subject ?? 'Reset your password') !!}

<p>Hello,</p>

<p>We received a request to reset the password for <strong>{{ $email }}</strong> on <strong>{{ $app_name }}</strong>.</p>

<p style="text-align: center;">
    <a href="{{ $reset_url }}" class="email-button">Choose a new password</a>
</p>

<p>This link can be used once and expires in {{ $expiry_hours }} hour{{ $expiry_hours != 1 ? 's' : '' }}. Asking for another link ends this one.</p>

<p class="email-muted">
    If you did not ask to reset your password, you can ignore this email: your password has not been changed.
</p>

{!! \App\RSpade\Lib\Messaging\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
