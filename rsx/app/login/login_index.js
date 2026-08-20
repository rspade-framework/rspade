/**
 * Login form enhancement
 *
 * Provides client-side validation for the login form
 */
class Login_Index {
    /**
     * Initialize the login page
     * This method is automatically called by RSX framework for any class with a static on_app_ready() method
     * No manual registration is required
     */
    static on_app_ready() {
        // Check if we're on this specific page
        if (!$('.Login_Index').exists()) return;

        let $form = $('#login-form');
        let $submit_button = $('#btn-submit');

        // Add form validation
        $submit_button.on('click', async (e) => {
            e.preventDefault();

            const $email = $form.find('#email');
            const $password = $form.find('#password');

            // Build validation errors object
            const errors = {};

            if (!$email.val()) {
                errors.email = 'Email is required';
            } else if (!is_email($email.val())) {
                errors.email = 'Please enter a valid email address';
            }

            if (!$password.val()) {
                errors.password = 'Password is required';
            }

            // If there are validation errors, display them
            if (Object.keys(errors).length > 0) {
                await Form_Utils.apply_form_errors($form, errors);
                return false;
            }

            // Clear any previous errors and submit form
            Form_Utils.reset_form_errors($form);

            // And away we go
            $form.trigger('submit');
        });
    }
}
