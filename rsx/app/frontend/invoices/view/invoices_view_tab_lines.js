/**
 * Invoices_View_Tab_Lines - the Remove action on a DRAFT invoice's lines.
 *
 * Removing a line releases its entry back to Approved (Invoice_Model::remove_line()); the
 * shell reloads on 'invoice_changed'.
 */
class Invoices_View_Tab_Lines extends Component {
    on_create() {
        // One Ajax_Action for the Remove buttons: a retry after a lost answer replays.
        this.remove_action = Ajax.action();
    }

    on_render() {
        // NAMESPACED AND IDEMPOTENT: on_render() re-fires on every render. The button sits in
        // a data-href row; Record_Table leaves a click on a button alone.
        this.$.off('.ivtl');

        this.$.on('click.ivtl', '[data-action="remove-line"]', async (e) => {
            e.preventDefault();
            const id = int($(e.currentTarget).data('id'));
            const confirmed = await Modal.confirm(
                'Remove Line',
                'Remove this entry from the invoice?\n\nThe entry goes back to Approved and can be invoiced again.',
                'Remove'
            );
            if (!confirmed) return;

            await this.alive(this.remove_action.call(Frontend_Invoices_Controller.remove_line, {
                id: this.args.invoice.id,
                entry_id: id,
            }));
            Flash_Alert.success('Line removed');
            this.trigger('invoice_changed');
        });
    }
}
