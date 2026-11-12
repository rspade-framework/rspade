@rsx_id('Password_Changed_Email')
{!! \App\RSpade\Lib\Messaging\Mail\Rsx_Mail_Layout::header($subject ?? 'Your password was changed') !!}

<p>Hello,</p>

<p>
    The password for <strong>{{ $email }}</strong> on
    {{ $app_name }}{{ $is_portal ? ' (client portal)' : '' }} {{ $what_happened }}.
</p>

<p>
    <strong>When:</strong> {{ $changed_when }}<br>
    <strong>From:</strong> {{ $ip_address ?? 'an unrecorded address' }}
</p>

<p>Every other device signed in to the account was signed out.</p>

<p><strong>If this wasn't you, contact an administrator.</strong></p>

{!! \App\RSpade\Lib\Messaging\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
