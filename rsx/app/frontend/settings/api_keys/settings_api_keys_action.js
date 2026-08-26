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

    /**
     * API access is identity state (users.is_api_access_enabled), read through the same
     * predicate the API dispatcher and the docs console read. Computed here rather than in
     * on_load() because nothing has to be fetched for it - it is already on the page's user.
     *
     * The key is deliberately NOT named error_data: that key belongs to
     * <Universal_Error_Page_Component> and its code-quality rule, and this is a local
     * permission flag, not a caught error.
     */
    on_create() {
        this.data.has_api_access = Permission.has_api_access();
    }

    on_ready() {
        // No key management UI exists without API access - nothing to wire.
        if (!this.data.has_api_access) {
            return;
        }

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
