/**
 * Billing_Receipt - an expense entry's receipt: the file (a download link), and while the
 * entry can still be edited by this viewer, an Upload / Replace button.
 *
 * The receipt is ONE file attachment on the BASE entry (Billable_Entry_Model::RECEIPT_CATEGORY,
 * never on its detail row). Uploading goes through the one browser upload transport,
 * Ajax.upload(), then claims the file with attach_receipt, which replaces any earlier receipt.
 * Triggers 'changed' after a receipt is attached, so the page can refresh.
 *
 * $entry_id   - the entry
 * $can_change - show the upload button (the entry is editable and the viewer may change it)
 */
class Billing_Receipt extends Component {
    on_create() {
        this.data.receipt = null;
        this.attach_action = Ajax.action();
    }

    async on_load() {
        const result = await Frontend_Billing_Controller.receipt({ id: this.args.entry_id });
        this.data.receipt = result.receipt;
    }

    on_render() {
        // The button and the file input are this template's own markup, rebuilt per render.
        this.$sid('upload_btn').off('click.brc').on('click.brc', () => this.$sid('file_input').click());
        this.$sid('file_input').off('change.brc').on('change.brc', () => {
            const file = this.$sid('file_input')[0].files[0];
            if (file) {
                this._upload(file);
            }
        });
    }

    async _upload(file) {
        this.$sid('upload_btn').prop('disabled', true);

        const form_data = new FormData();
        form_data.append('file', file);

        // An upload refusal (the size ceiling, the upload gate, the transport) is an expected
        // outcome here and is shown to the user; the claim's own errors are not caught.
        let uploaded = null;
        try {
            uploaded = await this.alive(Ajax.upload(form_data));
        } catch (e) {
            this.$sid('upload_btn').prop('disabled', false);
            this.$sid('file_input').val('');
            Modal.error(e.message || 'The receipt could not be uploaded.', 'Upload failed');
            return;
        }

        await this.alive(this.attach_action.call(Frontend_Billing_Controller.attach_receipt, {
            id: this.args.entry_id,
            key: uploaded.attachment.key,
        }));

        await this.alive(this.reload());
        this.trigger('changed');
    }
}
