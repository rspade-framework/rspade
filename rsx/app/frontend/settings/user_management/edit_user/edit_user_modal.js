/**
 * Edit_User_Modal - Modal for editing existing user information
 *
 * Displays form to update user profile information.
 * Uses Edit_User_Modal_Form component for UI and validation.
 *
 * Returns updated user record on success, false on cancel.
 */
class Edit_User_Modal extends Modal_Abstract {
    /**
     * Show edit user modal
     *
     * @param {number} user_id - ID of user to edit
     * @returns {Promise<Object|false>} Updated user record on success, false on cancel
     */
    static async show(user_id) {
        // Load user data for editing
        let user_data;
        try {
            user_data = await Frontend_Settings_User_Management_Controller.get_user_for_edit({user_id});
        } catch (error) {
            await Modal.error(error, 'Failed to Load User');
            return false;
        }

        const result = await Modal.form({
            title: 'Edit User',
            component: 'Edit_User_Modal_Form',
            component_args: {data: user_data},
            on_submit: async (form) => {
                try {
                    const values = form.vals();
                    const result = await Frontend_Settings_User_Management_Controller.save_user(values);
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
