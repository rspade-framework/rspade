/**
 * Api_Keys_DataGrid - JavaScript for API keys datagrid
 *
 * Handles the view-scope and revoke button clicks.
 */
class Api_Keys_DataGrid extends DataGrid_Abstract {
    on_ready() {
        super.on_ready();

        // Handle view-scope button clicks
        this.$.on('click', '[data-action="view_scope"]', async (e) => {
            e.preventDefault();
            e.stopPropagation();

            const $btn = $(e.currentTarget);

            await Api_Keys_DataGrid._show_key_scopes($btn.data('id'));
        });

        // Handle revoke button clicks
        this.$.on('click', '[data-action="revoke"]', async (e) => {
            e.preventDefault();
            e.stopPropagation();

            const $btn = $(e.currentTarget);
            const key_id = $btn.data('id');

            const confirmed = await Modal.confirm(
                'Revoke API Key',
                'Are you sure you want to revoke this API key?\n\nThis action cannot be undone.',
                'Revoke Key'
            );

            if (!confirmed) {
                return;
            }

            try {
                await Frontend_Settings_Api_Keys_Controller.revoke_key({ id: key_id });
                this.reload();
            } catch (error) {
                Modal.alert({
                    title: 'Error',
                    message: error.message || 'Failed to revoke API key',
                });
            }
        });
    }

    /**
     * The read-only scope view for one key.
     *
     * A dialog with no endpoint behind it, so it is Modal.show() around a component rather
     * than Modal.form(). The component fetches the key itself - the grid row carries only
     * the summary.
     */
    static async _show_key_scopes(key_id) {
        const $container = $('<div>');
        const body = $container.component('Api_Key_Scopes_Modal_Body', { key_id: key_id }).component();

        await new Promise((resolve) => {
            body.on('ready', () => resolve());
        });

        await Modal.show({
            title: 'API Key Scope',
            body: body.$,
            buttons: [{
                label: 'Close',
                value: true,
                class: 'btn-primary',
                default: true,
            }],
            max_width: 640,
            closable: true,
            close_on_submit: true,
        });
    }
}
