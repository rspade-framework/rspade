@rsx_id('Site_Selection_Index')
@rsx_extends('Login_Layout')

@section('title', 'Select Site')
@section('card_title', 'Multiple Sites')
@section('card_subtitle', 'Site switching functionality coming soon')

@section('content')
    <div class="alert alert-info text-center">
        You are associated with multiple sites. Site switching functionality is not yet implemented.
    </div>

    <div class="text-center mt-4">
        <p class="text-muted">
            This feature is under development. For now, you will be automatically directed to your primary site.
        </p>
    </div>

    <div class="text-center mt-4">
        <small class="text-muted">
            <a href="{{ Rsx::Route('Login_Controller::logout') }}">Logout</a>
        </small>
    </div>
@endsection
