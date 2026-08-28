/**
 * Edit_User_Modal - Modal for editing existing user information
 *
 * The dialog opens IMMEDIATELY; Edit_User_Modal_Form loads the record itself and wears
 * the form's loading overlay until the values land, so the fields are never shown
 * blank and a submit cannot race the fetch. The endpoint is the form's own
 * $controller/$method.
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
        const result = await Modal.form({
            title: 'Edit User',
            component: 'Edit_User_Modal_Form',
            component_args: { user_id: int(user_id) },
            submit_label: 'Save User',
        });

        return result || false;
    }
}
