/**
 * Settings Group Management View Action
 *
 * View single group details with admin actions.
 */
@route('/frontend/settings/group_management/:id')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_manage_users')
class Settings_Group_Management_View_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.group = {
            id: null,
            name: '',
            description: '',
            deletion_protection: false,
            member_count: 0,
            members: [],
            created_at: null,
            updated_at: null,
        };
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            this.data.group = await Frontend_Settings_Group_Management_Controller.get_group({
                id: this.args.id
            });
        } catch (e) {
            this.data.error_data = e;
        }
        this.data.loading = false;
    }

    on_ready() {
        let that = this;

        // Handle Edit Group button click
        that.$sid('btn_edit_group').click(async function () {
            await that.handle_edit_group();
        });

        // Handle Delete Group button click
        that.$sid('btn_delete_group').click(async function () {
            await that.handle_delete_group();
        });
    }

    /**
     * Edit group workflow: show edit modal, reload on save
     */
    async handle_edit_group() {
        let that = this;

        // Show edit group modal
        const result = await Edit_Group_Modal.show(that.data.group.id);

        if (result) {
            // Reload action to show updated group information
            that.reload();
        }
    }

    /**
     * Delete group workflow: confirm and delete
     */
    async handle_delete_group() {
        let that = this;

        // Confirm deletion
        const confirmed = await Modal.confirm(
            'Delete Group',
            `Are you sure you want to delete the group "${that.data.group.name}"?\n\nThis action cannot be undone.`,
            'Delete',
            'Cancel'
        );

        if (confirmed) {
            try {
                await Frontend_Settings_Group_Management_Controller.delete_group({
                    id: that.data.group.id
                });

                // Navigate back to group list
                Spa.dispatch(Rsx.Route('Settings_Group_Management_Index_Action'));
            } catch (e) {
                Flash_Alert.error(e.message || 'Failed to delete group');
            }
        }
    }

    // Breadcrumb methods
    async page_title() {
        await this.await_loaded();
        return this.data.group.name || 'Group';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.group.name || 'Group';
    }

    async breadcrumb_label_active() {
        return 'View Group';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Settings_Group_Management_Index_Action');
    }
}
