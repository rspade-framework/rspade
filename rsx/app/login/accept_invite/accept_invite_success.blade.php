@rsx_id('Accept_Invite_Success')
@rsx_extends('Login_Layout')

@section('title', 'Account Created')
@section('card_title', 'Account Created Successfully')
@section('card_subtitle', 'You have been logged in')

@section('content')
    <div class="alert alert-success text-center">
        Your account has been created and you have been logged in to <strong>{{ $site_name }}</strong>.
    </div>

    <div class="d-grid">
        <a href="{{ Rsx::Route('Dashboard_Index_Action') }}" class="btn btn-primary">
            Go To Dashboard
        </a>
    </div>
@endsection
