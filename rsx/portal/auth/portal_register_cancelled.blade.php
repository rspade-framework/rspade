@rsx_id('Portal_Register_Cancelled')
@rsx_extends('Portal_Auth_Layout')

@section('title', 'Invitation Cancelled')
@section('card_title', 'Invitation Cancelled')
@section('card_subtitle', 'This invitation is no longer available')

@section('content')
    <div class="alert alert-danger text-center">
        This invitation has been cancelled by the firm and can no longer be used.
    </div>

    <div class="text-center">
        <small class="text-muted">
            If you believe this is a mistake, please contact the firm to request a new invitation.
        </small>
    </div>
@endsection
