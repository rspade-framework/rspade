/**
 * Edit_Group_Modal - Modal for editing existing groups
 *
 * Loads group data and available users first, then displays form.
 * Returns updated group record on success, false on cancel.
 */
class Edit_Group_Modal extends Modal_Abstract {
    /**
     * Show edit group modal
     *
     * @param {number} group_id - ID of the group to edit
     * @returns {Promise<Object|false>} Group record on success, false on cancel
     */
    static async show(group_id) {
        // Load group data and available users in parallel
        let group_data, users_data;
        try {
            [group_data, users_data] = await Promise.all([
                Frontend_Settings_Group_Management_Controller.get_group_for_edit({ group_id: group_id }),
                Frontend_Settings_Group_Management_Controller.get_selectable_users({ group_id: group_id })
            ]);
        } catch (error) {
            Flash_Alert.error(error.message || 'Failed to load group data');
            return false;
        }

        // Format users for Checkbox_Multiselect
        const user_options = users_data.users.map(user => ({
            id: user.id,
            label: user.display_name,
            subtitle: !user.is_active ? 'inactive' : null
        }));

        const result = await Modal.form({
            title: 'Edit Group',
            component: 'Edit_Group_Modal_Form',
            component_args: {
                group_data: group_data,
                user_options: user_options
            },
            on_submit: async (form) => {
                try {
                    const result = await Frontend_Settings_Group_Management_Controller.save_group(form.vals());
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
