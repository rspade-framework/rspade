@rsx_id('Portal_Shared_Content_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Content Shared With You') !!}

<p>Hello,</p>

<p><strong>{{ $shared_by_name }}</strong> has shared content with you from <strong>{{ $app_name }}</strong>.</p>

@if(!empty($message))
<div style="background-color: #f8f9fa; border-left: 4px solid #3498db; padding: 12px 16px; margin: 16px 0; border-radius: 0 4px 4px 0;">
    <p style="margin: 0; font-style: italic;">{{ $message }}</p>
</div>
@endif

<p>Click the button below to view the shared content:</p>

<p style="text-align: center;">
    <a href="{{ $view_url }}" class="email-button">View Content</a>
</p>

<p style="font-size: 13px; color: #868e96;">
    You may need to create an account or log in to view this content.
    @if(!empty($expires_at))
        This link expires on {{ $expires_at }}.
    @endif
</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
