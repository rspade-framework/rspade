@rsx_id('Welcome_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Welcome') !!}

<p>Hello{{ !empty($name) ? ' ' . e($name) : '' }},</p>

<p>Welcome to <strong>{{ $app_name }}</strong>! Your account has been created successfully.</p>

<p>You can log in at any time using the button below:</p>

<p style="text-align: center;">
    <a href="{{ $login_url }}" class="email-button">Log In</a>
</p>

<p style="font-size: 13px; color: #868e96;">
    If you have any questions, please contact your administrator.
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
