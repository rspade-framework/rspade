/**
 * Add_User_Modal - Modal for adding/inviting new users to site
 *
 * The dialog is chrome: Add_User_Modal_Form declares the endpoint on its own
 * <Rsx_Form>, and Modal.form() drives that form's submit().
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
            submit_label: 'Send Invitation',
        });

        return result || false;
    }
}
