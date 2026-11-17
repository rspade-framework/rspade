/**
 * Invoices_View_Sidebar - the lifecycle handlers: Issue and Mark Paid are dialogs around a form
 * on the transition's endpoint (the dates are the user's to set; the server validates them),
 * Void is a confirmation. Each fires 'invoice_changed' for the shell to reload.
 */
class Invoices_View_Sidebar extends Component {
    on_create() {
        // One Ajax_Action for Void: a retry after a lost answer replays.
        this.void_action = Ajax.action();
    }

    on_render() {
        // NAMESPACED AND IDEMPOTENT: on_render() re-fires on every render.
        this.$.off('.ivs');

        this.$.on('click.ivs', '[data-action="issue"]', async () => {
            const result = await this.alive(Modal.form({
                title: 'Issue Invoice',
                component: 'Invoices_Issue_Form',
                component_args: { id: this.args.invoice.id },
                submit_label: 'Issue',
                max_width: 560,
            }));
            if (result) {
                Flash_Alert.success('Invoice issued');
                this.trigger('invoice_changed');
            }
        });

        this.$.on('click.ivs', '[data-action="mark-paid"]', async () => {
            const result = await this.alive(Modal.form({
                title: 'Mark Invoice Paid',
                component: 'Invoices_Mark_Paid_Form',
                component_args: { id: this.args.invoice.id },
                submit_label: 'Mark Paid',
                max_width: 560,
            }));
            if (result) {
                Flash_Alert.success('Invoice marked paid');
                this.trigger('invoice_changed');
            }
        });

        // Destructive action lives inside the overflow Action_Menu, never as a peer button.
        this.$.on('click.ivs', '[data-action="void"]', async () => {
            const confirmed = await this.alive(Modal.confirm(
                'Void Invoice',
                'Void ' + this.args.invoice.invoice_number_formatted + '?\n\nEvery entry on it goes back to Approved and can be invoiced again. The invoice number is not reused.',
                'Void Invoice',
                'Cancel'
            ));
            if (!confirmed) return;

            await this.alive(this.void_action.call(Frontend_Invoices_Controller.void, { id: this.args.invoice.id }));
            Flash_Alert.success('Invoice voided');
            this.trigger('invoice_changed');
        });
    }
}
