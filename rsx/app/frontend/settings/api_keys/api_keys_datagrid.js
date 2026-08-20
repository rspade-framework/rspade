/**
 * Api_Keys_DataGrid - JavaScript for API keys datagrid
 *
 * Handles revoke button clicks.
 */
class Api_Keys_DataGrid extends DataGrid_Abstract {
    on_ready() {
        super.on_ready();

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
}
