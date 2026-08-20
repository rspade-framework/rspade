@rsx_id('Portal_Request_Reply_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Client reply on a request') !!}

<p>Hello {{ $staff_name }},</p>

<p><strong>{{ $replier_name }}</strong> ({{ $client_name }}) replied to the request <strong>"{{ $thread_title }}"</strong>.</p>

<div style="background-color: #f8f9fa; border-left: 4px solid #3498db; padding: 12px 16px; margin: 16px 0; border-radius: 0 4px 4px 0;">
    <p style="margin: 0; font-style: italic;">{{ $message }}</p>
</div>

<p>Open the request to review their response:</p>

<p style="text-align: center;">
    <a href="{{ $view_url }}" class="email-button">View Request</a>
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer() !!}
