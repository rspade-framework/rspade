/**
 * Signup_Index
 *
 * Handles signup form success callback
 */
class Signup_Index {
    /**
     * Handle successful signup
     * Shows the created user record as JSON and provides next steps
     */
    static async on_success(response) {
        // Show success message with user data for now
        // Will expand to email verification flow later
        const user_json = json_encode(response.user, null, 2);

        await Modal.alert(
            'Account Created',
            `Your account has been created successfully!\n\nUser Record:\n${user_json}\n\n${response.message || 'Please check your email to verify your account.'}`
        );

        // Redirect to login page
        window.location.href = Rsx.Route('Login_Controller');
    }
}
