@rsx_id('Password_Reset_Invalid')
@rsx_extends('Login_Layout')

@section('title', 'Password Reset Unavailable')
@section('card_title', 'Password Reset Unavailable')
@section('card_subtitle', 'This link cannot be used')

@section('content')
    <div class="alert alert-danger text-center">{{ $error }}</div>

    <div class="d-grid gap-2">
        <a href="{{ Rsx::Route('Password_Reset_Controller::request') }}" class="btn btn-primary">Request a New Link</a>
        <a href="{{ Rsx::Route('Login_Controller::index') }}" class="btn btn-secondary">Go to Sign In</a>
    </div>
@endsection
