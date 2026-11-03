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

    on_render() {
        // Own markup, bound in on_render(); namespaced and idempotent because on_render()
        // re-fires on every render.
        let that = this;

        // Handle Add User button click
        that.$sid('btn_add_user').off('click.user_management').on('click.user_management', async function () {
            await that.handle_add_user();
        });
    }

    /**
     * Add user workflow: show add modal, refresh grid, show the invitation it sent.
     *
     * add_user mints the invitation and emails it; its link is shown as it is. Calling
     * send_invite here would mint a second code, ending the link in the first email.
     */
    async handle_add_user() {
        let that = this;

        // Show add user modal
        const user = await that.alive(Add_User_Modal.show());

        if (user) {
            // Refresh the user list
            that.sid('users_datagrid').reload();

            // Show the invitation add_user sent
            await Send_User_Invite_Modal.show_sent(user.invite_url);
        }
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Manage users and permissions';
    }
}
