/**
 * Party_DataGrid - handles row delete clicks (confirm -> delete -> reload).
 */
class Party_DataGrid extends DataGrid_Abstract {
    on_ready() {
        super.on_ready();

        this.$.on('click', '[data-action="delete"]', async (e) => {
            e.preventDefault();
            e.stopPropagation();

            const id = $(e.currentTarget).data('id');

            const confirmed = await Modal.confirm(
                'Delete Party',
                'Delete this party?\n\nThis also removes its type-specific detail.',
                'Delete'
            );

            if (!confirmed) {
                return;
            }

            try {
                await Frontend_Party_Controller.delete({ id });
                this.reload();
            } catch (error) {
                Modal.alert('Error', error.message || 'Failed to delete party');
            }
        });
    }
}
