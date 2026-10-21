@rsx_id('Create_Account')
@rsx_extends('Login_Layout')

@section('title', 'Create Account')
@section('card_title', 'Create Your Account')
@section('card_subtitle', 'Complete your registration')

@section('content')
    {{-- Every rule is the endpoint's. The lengths are the columns' own, read server-side
         because this bundle carries no model. --}}
    @php
        $form_data = [];
        if (isset($invite_code) && $invite_code) {
            $form_data['invite_code'] = $invite_code;
        }
        if (isset($invitation) && $invitation) {
            $form_data['email'] = $invitation->email;
            $form_data['first_name'] = $invitation->first_name;
            $form_data['last_name'] = $invitation->last_name;
        }
    @endphp

    <Rsx_Form $data="{!! json_encode($form_data) !!}" $controller="Accept_Invite_Controller" $method="create_account_submit">
        <Form_Errors />

        @if (isset($invite_code) && $invite_code)
            <Hidden_Input $name="invite_code" />
        @endif

        <div class="row">
            <div class="col-md-6">
                <Form_Field $label="First Name" $required=true>
                    <Text_Input $name="first_name" $max_length="{{ (int) \User_Model::field_length('first_name') }}" />
                </Form_Field>
            </div>
            <div class="col-md-6">
                <Form_Field $label="Last Name" $required=true>
                    <Text_Input $name="last_name" $max_length="{{ (int) \User_Model::field_length('last_name') }}" />
                </Form_Field>
            </div>
        </div>

        <Form_Field $label="Email Address" $required=true $help="This email address is set by your invitation and cannot be changed">
            <Text_Input $name="email" $type="email" $disabled=true $max_length="{{ (int) \Login_User_Model::field_length('email') }}" />
        </Form_Field>

        <Form_Field $label="Password" $required=true>
            <Text_Input $name="password" $type="password" $max_length=-1 />
        </Form_Field>

        <Form_Field $label="Confirm Password" $required=true>
            <Text_Input $name="password_confirm" $type="password" $max_length=-1 />
        </Form_Field>

        <div class="mb-3 d-flex justify-content-center"><Turnstile_Input /></div>

        <div class="d-grid mt-4">
            <button class="btn btn-primary" type="submit">
                Create Account
            </button>
        </div>

        <div class="mt-3 text-center">
            <small class="text-muted">
                Already have an account? <a href="{{ Rsx::Route('Login_Controller') }}">Sign in</a>
            </small>
        </div>
    </Rsx_Form>
@endsection
