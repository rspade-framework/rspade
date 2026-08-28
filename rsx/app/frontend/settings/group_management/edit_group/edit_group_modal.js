/**
 * Edit_Group_Modal - Modal for editing existing groups
 *
 * The dialog opens IMMEDIATELY and Edit_Group_Modal_Form loads the record itself: the
 * form wears a loading overlay until the values land, so the user never sees empty
 * fields on an edit surface (and cannot submit blanks over data the fetch has not
 * reached yet). The endpoint is the form's own $controller/$method.
 *
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
        const result = await Modal.form({
            title: 'Edit Group',
            component: 'Edit_Group_Modal_Form',
            component_args: { group_id: int(group_id) },
            submit_label: 'Save Group',
        });

        return result || false;
    }
}
