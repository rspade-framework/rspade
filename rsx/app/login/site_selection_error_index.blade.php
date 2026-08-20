@rsx_id('Site_Selection_Error_Index')
@rsx_extends('Login_Layout')

@section('title', 'Access Denied')
@section('card_title', 'Access Denied')
@section('card_subtitle', '')

@section('content')
    <div class="alert alert-danger">
        You have not been added to this account, please contact the account administrator for assistance.
    </div>
    <div class="d-grid">
        <a href="{{ Rsx::Route('Dashboard_Index_Action') }}" class="btn btn-primary">
            Return to Dashboard
        </a>
    </div>
@endsection
