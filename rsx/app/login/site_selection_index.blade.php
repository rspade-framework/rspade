@rsx_id('Site_Selection_Index')
@rsx_extends('Login_Layout')

@section('title', 'Select Site')
@section('card_title', 'Select a Site')
@section('card_subtitle', 'Your account belongs to more than one site')

@section('content')
    <p class="mb-4">Choose the site to work in.</p>

    @rsx_include('Login_Site_Picker', ['sites' => $sites])

    <div class="text-center">
        <small class="text-muted">
            <a href="{{ Rsx::Route('Login_Controller::logout') }}">Logout</a>
        </small>
    </div>
@endsection
