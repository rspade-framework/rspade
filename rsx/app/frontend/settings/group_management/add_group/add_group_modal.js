/**
 * Add_Group_Modal - Modal for adding new groups
 *
 * The dialog is chrome: Add_Group_Modal_Form declares the endpoint on its own
 * <Rsx_Form>, and Modal.form() drives that form's submit(). The selectable users are
 * loaded before opening because the member checkboxes need them to render.
 *
 * Returns created group record on success, false on cancel.
 */
class Add_Group_Modal extends Modal_Abstract {
    /**
     * Show add group modal
     *
     * @returns {Promise<Object|false>} Group record on success, false on cancel
     */
    static async show() {
        // Load available users first
        let users_data;
        try {
            users_data = await Frontend_Settings_Group_Management_Controller.get_selectable_users({});
        } catch (error) {
            Flash_Alert.error(error.message || 'Failed to load users');
            return false;
        }

        // Format users for Checkbox_Multiselect
        const user_options = users_data.users.map(user => ({
            id: user.id,
            label: user.display_name,
            subtitle: !user.is_active ? 'inactive' : null
        }));

        const result = await Modal.form({
            title: 'Add Group',
            component: 'Add_Group_Modal_Form',
            component_args: {
                user_options: user_options
            },
            submit_label: 'Add Group',
        });

        return result || false;
    }
}
