@rsx_id('Site_Unauthorized_Index')
@rsx_extends('Login_Layout')

@section('title', 'Site Unauthorized')
@section('card_title', 'Access Denied')
@section('card_subtitle', 'You are not authorized for this site')

@section('content')
    <div class="alert alert-warning">
        <strong>You are not authorized to access this site.</strong><br>
        Your account may have been removed from this site or your access has been revoked.
    </div>

    <p class="mb-4">
        However, you still have access to the following sites. Please select one to continue:
    </p>

    @rsx_include('Login_Site_Picker', ['sites' => $sites])

    <div class="text-center">
        <small class="text-muted">
            <a href="{{ Rsx::Route('Login_Controller::logout') }}">Logout and sign in as different user</a>
        </small>
    </div>
@endsection
