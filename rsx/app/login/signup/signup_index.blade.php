@rsx_id('Signup_Index')
@rsx_extends('Login_Layout')

@section('title', 'Sign Up')
@section('card_title', 'Create Your Workspace')
@section('card_subtitle', 'We will email you a code to confirm your address')

@section('content')
    {{-- Reached only while rsx.auth.self_signup is on (Signup_Controller answers 404 otherwise).
         Every rule is the endpoint's; the lengths below are the columns' own, read server-side
         because this bundle carries no model. --}}
    <Rsx_Form id="signup-form" $controller="Signup_Controller" $method="submit">
        <Form_Errors />

        <Form_Field $label="Organization Name" $required=true>
            <Text_Input $name="site_name" $max_length="{{ (int) $max_site_name }}" />
        </Form_Field>

        <div class="row">
            <div class="col-tablet-6">
                <Form_Field $label="First Name" $required=true>
                    <Text_Input $name="first_name" $max_length="{{ (int) $max_first_name }}" />
                </Form_Field>
            </div>
            <div class="col-tablet-6">
                <Form_Field $label="Last Name" $required=true>
                    <Text_Input $name="last_name" $max_length="{{ (int) $max_last_name }}" />
                </Form_Field>
            </div>
        </div>

        <Form_Field $label="Email Address" $required=true>
            <Text_Input $name="email" $type="email" $max_length="{{ (int) $max_email }}" />
        </Form_Field>

        <Form_Field $label="Password" $required=true>
            <Text_Input $name="password" $type="password" $max_length=-1 />
        </Form_Field>

        <Form_Field $label="Confirm Password" $required=true>
            <Text_Input $name="password_confirm" $type="password" $max_length=-1 />
        </Form_Field>

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

@section('below_card')
    <Turnstile_Input $form="signup-form" />
@endsection
