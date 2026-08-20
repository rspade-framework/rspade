/**
 * Settings API Keys Action
 *
 * API key management page with DataGrid and add/revoke functionality.
 */
@route('/frontend/settings/api_keys')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('API Keys')
@auth('is_logged_in')
class Settings_Api_Keys_Action extends Spa_Action {
    scaffolded = true;

    on_ready() {
        // Handle "Generate New Key" button
        this.$sid('btn_add_key').click(async () => {
            const result = await Add_Api_Key_Modal.show();
            if (result) {
                // Reload the datagrid to show the new key
                this.sid('api_keys_datagrid').reload();
            }
        });
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Manage API keys and access tokens';
    }
}
