@rsx_id('Portal_Invitation_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Invitation') !!}

<p>Hello,</p>

@if(!empty($existing_account))
    <p>You have been invited to access <strong>{{ $client_name }}</strong>'s client portal.</p>

    <p>Sign in to your existing portal account and you'll see this invitation waiting on your dashboard. Accept it to gain access:</p>

    <p style="text-align: center;">
        <a href="{{ $registration_url }}" class="email-button">Sign In to Accept</a>
    </p>
@else
    <p>You have been invited to join the <strong>{{ $app_name }}</strong> client portal.</p>

    <p>Click the button below to create your account and get started:</p>

    <p style="text-align: center;">
        <a href="{{ $registration_url }}" class="email-button">Create Your Account</a>
    </p>
@endif

<p>This invitation expires in {{ $expiry_days }} days.</p>

<p style="font-size: 13px; color: #868e96;">
    If you did not expect this invitation, you can safely ignore this email.
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
