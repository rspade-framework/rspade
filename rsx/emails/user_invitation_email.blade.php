@rsx_id('User_Invitation_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'You\'re Invited') !!}

<p>Hello{{ !empty($name) ? ' ' . e($name) : '' }},</p>

<p>You have been invited to join <strong>{{ $app_name }}</strong>.</p>

<p>Click the button below to create your account and get started:</p>

<p style="text-align: center;">
    <a href="{{ $invite_url }}" class="email-button">Accept Invitation</a>
</p>

<p>This invitation expires in {{ $expiry_days }} days.</p>

<p style="font-size: 13px; color: #868e96;">
    If you did not expect this invitation, you can safely ignore this email.
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
