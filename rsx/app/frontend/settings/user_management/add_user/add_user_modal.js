/**
 * Add_User_Modal - Modal for adding/inviting new users to site
 *
 * Displays form to collect user information and create pending invitation.
 * Uses Add_User_Modal_Form component for UI and validation.
 *
 * Returns created user record on success, false on cancel.
 */
class Add_User_Modal extends Modal_Abstract {
    /**
     * Show add user modal
     *
     * @returns {Promise<Object|false>} User record on success, false on cancel
     */
    static async show() {
        const result = await Modal.form({
            title: 'Add User',
            component: 'Add_User_Modal_Form',
            on_submit: async (form) => {
                try {
                    const values = form.vals();
                    const result = await Frontend_Settings_User_Management_Controller.add_user(values);
                    return result; // Close modal, return user data
                } catch (error) {
                    // Render error (form handles both validation and generic errors)
                    await form.render_error(error);
                    return false; // Keep modal open
                }
            },
        });

        return result || false;
    }
}
