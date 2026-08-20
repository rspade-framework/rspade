/**
 * Add_Group_Modal - Modal for adding new groups
 *
 * Loads available users first, then displays form.
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
            on_submit: async (form) => {
                try {
                    const values = form.vals();
                    const result = await Frontend_Settings_Group_Management_Controller.add_group(values);
                    return result; // Close modal, return group data
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
