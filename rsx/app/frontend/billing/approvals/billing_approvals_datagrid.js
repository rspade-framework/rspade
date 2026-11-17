/**
 * Billing_Approvals_DataGrid - per-row Approve / Reject. A decided entry leaves the queue, so
 * the grid reloads after each decision.
 */
class Billing_Approvals_DataGrid extends DataGrid_Abstract {
    static record_noun_plural = 'entries';

    on_create() {
        super.on_create();
        // One Ajax_Action for the Approve buttons: a retry after a lost answer replays.
        this.approve_action = Ajax.action();
    }

    on_render() {
        // NAMESPACED AND IDEMPOTENT: this.$ survives every render() while on_render() re-fires
        // on each one. Delegated from the root, because the rows repaint on every page load.
        // stopPropagation keeps the row's data-href from navigating.
        this.$.off('.badg');

        this.$.on('click.badg', '[data-action="approve"]', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            const id = int($(e.currentTarget).data('id'));
            if (await this.alive(Billing_Entry_Actions.approve(id, this.approve_action))) {
                Flash_Alert.success('Entry approved');
                this.reload();
            }
        });

        this.$.on('click.badg', '[data-action="reject"]', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            const id = int($(e.currentTarget).data('id'));
            if (await this.alive(Billing_Entry_Actions.reject(id))) {
                Flash_Alert.success('Entry rejected');
                this.reload();
            }
        });
    }
}
