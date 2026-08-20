@rsx_id('Portal_Impersonate_Stopped')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Impersonation Ended')
@section('card_title', 'Impersonation Ended')
@section('card_subtitle', 'You have exited the read-only client view')

@section('content')
    <div class="alert alert-success text-center">
        The "View as Client" session has ended. You may close this tab.
    </div>

    <div class="text-center">
        <small class="text-muted">
            Your staff session in the main application was not affected.
        </small>
    </div>
@endsection
