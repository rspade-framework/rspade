/**
 * Settings Group Management Index Action
 *
 * Group management list with DataGrid. Uses Settings_Layout wrapper.
 * No three-state loading pattern needed - DataGrid handles its own loading.
 */
@route('/frontend/settings/group_management')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Group Management')
@auth('is_logged_in', 'can_manage_users')
class Settings_Group_Management_Index_Action extends Spa_Action {
    scaffolded = true;

    on_ready() {
        let that = this;

        // Handle Add Group button click
        that.$sid('btn_add_group').click(async function () {
            await that.handle_add_group();
        });
    }

    /**
     * Add group workflow: show add modal, refresh grid on success
     */
    async handle_add_group() {
        let that = this;

        // Show add group modal
        const group = await Add_Group_Modal.show();

        if (group) {
            // Refresh the group list
            that.sid('groups_datagrid').reload();
        }
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Manage user groups';
    }
}
