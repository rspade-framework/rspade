/**
 * Settings Portal Users Index Action
 *
 * Global portal-user admin screen: lists ALL portal users for the current site
 * (across clients) with status, client membership(s), last login, and the
 * account-level suspend / reactivate ban toggle. Uses Settings_Layout.
 *
 * Suspend here is the GLOBAL "ban this account entirely" action - distinct from the
 * per-client "Disable Access" on the members table (which only removes a client
 * membership). It is delegated to the shared Portal_User_Admin_Actions class.
 * The DataGrid handles its own loading.
 */
@route('/frontend/settings/portal_users')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Portal Users')
@auth('is_logged_in')
class Settings_Portal_Users_Index_Action extends Spa_Action {
    scaffolded = true;

    on_ready() {
        const grid = () => this.sid('portal_users_datagrid');

        // Suspend
        this.$.on('click', '[data-portal-action="suspend"]', async (e) => {
            const portal_user_id = int($(e.currentTarget).data('portal-user'));
            if (await Portal_User_Admin_Actions.suspend(portal_user_id)) grid().reload();
        });

        // Reactivate
        this.$.on('click', '[data-portal-action="reactivate"]', async (e) => {
            const portal_user_id = int($(e.currentTarget).data('portal-user'));
            if (await Portal_User_Admin_Actions.reactivate(portal_user_id)) grid().reload();
        });
    }


    async breadcrumb_label_active() {
        return 'Manage portal users';
    }
}
