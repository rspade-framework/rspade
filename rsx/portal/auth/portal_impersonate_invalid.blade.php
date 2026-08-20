@rsx_id('Portal_Impersonate_Invalid')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Link Expired')
@section('card_title', 'Link Expired')
@section('card_subtitle', 'This "View as Client" link is no longer valid')

@section('content')
    <div class="alert alert-danger text-center">
        This impersonation link has expired or has already been used. Please start
        again from the contact in the staff application.
    </div>

    <div class="text-center">
        <small class="text-muted">
            For security, each "View as Client" link works only once and for a short time.
        </small>
    </div>
@endsection
