@rsx_id('Api_Key_Notice_Email')
{!! \App\RSpade\Lib\Messaging\Mail\Rsx_Mail_Layout::header($subject ?? 'API key notice') !!}

<p>Hello,</p>

<p>
    An API key on your {{ $app_name }} account (<strong>{{ $email }}</strong>) {{ $what_happened }}.
</p>

<p>
    <strong>Key:</strong> {{ $name }} ({{ $key_prefix }})<br>
    <strong>When:</strong> {{ $occurred_when }}<br>
    <strong>By:</strong> {{ $by_whom }}<br>
    <strong>From:</strong> {{ $ip_address ?? 'an unrecorded address' }}@if ($ip_address__location) ({{ $ip_address__location }})@endif
</p>

@if ($is_created)
    <p>
        <strong>Access:</strong> {{ $access }}<br>
        <strong>Scope:</strong> {{ $scope_lines ? 'limited to the paths below' : 'unrestricted - everything your account may do' }}<br>
        @foreach ($scope_lines as $scope_line)
            &nbsp;&nbsp;{{ $scope_line }}<br>
        @endforeach
        <strong>Expires:</strong> {{ $expires_when ?? 'never - it works until it is revoked' }}
    </p>

    <p>An API key acts as you. Anyone holding it can reach the application through its API with your permissions, as limited above.</p>
@endif

<p><strong>If this wasn't you, contact an administrator.</strong></p>

@if ($ip_address__location)
    <p class="email-muted">IP geolocation by <a href="https://db-ip.com">DB-IP</a></p>
@endif

{!! \App\RSpade\Lib\Messaging\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
