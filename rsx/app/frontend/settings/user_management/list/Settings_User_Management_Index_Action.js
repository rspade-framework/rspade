/**
 * Settings User Management Index Action
 *
 * User management list with DataGrid. Uses Settings_Layout wrapper.
 * No three-state loading pattern needed - DataGrid handles its own loading.
 */
@route('/frontend/settings/user_management')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('User Management')
@auth('is_logged_in', 'can_manage_users')
class Settings_User_Management_Index_Action extends Spa_Action {
    scaffolded = true;

    on_ready() {
        let that = this;

        // Handle Add User button click
        that.$sid('btn_add_user').click(async function () {
            await that.handle_add_user();
        });
    }

    /**
     * Add user workflow: show add modal, refresh grid, show invite modal
     */
    async handle_add_user() {
        let that = this;

        // Show add user modal
        const user = await Add_User_Modal.show();

        if (user) {
            // Refresh the user list
            that.sid('users_datagrid').reload();

            // Show send invite modal
            await Send_User_Invite_Modal.show(user.id);
        }
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Manage users and permissions';
    }
}
