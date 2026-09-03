/**
 * Clients_Request_Thread_Action - the staff view of a single request thread.
 *
 * Two-column layout: LEFT = the timeline (messages as chat cards, status events as
 * centered alert cards) followed by a reply composer (message + attach files + status
 * change + Send); RIGHT = vitals (title, status, participants) + the Needs Review and
 * Accepted document buckets. Clicking any document chip/card opens Document_Detail_Modal.
 *
 * Three-state (loading / error / content). on_load fetches request_thread_get; a reply
 * posts via request_thread_reply then reload()s.
 */
@route('/clients/view/:id/request/:thread_id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Clients_Request_Thread_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.thread = null;
        this.data.timeline = [];
        this.data.participants = { members: [], staff: [] };
        this.data.needs_review = [];
        this.data.accepted = [];
        this.data.error_data = null;
        this.data.loading = true;
        // Staff pending attachment keys (uploaded but not yet sent with a reply).
        this.state.pending_attachments = [];
    }

    async on_load() {
        try {
            const result = await Frontend_Clients_Controller.request_thread_get({id: this.args.thread_id});
            this.data.thread = result.thread;
            this.data.timeline = result.timeline;
            this.data.participants = result.participants;
            this.data.needs_review = result.needs_review;
            this.data.accepted = result.accepted;
        } catch (e) {
            this.data.error_data = e;
        }
        this.data.loading = false;
    }

    on_ready() {
        // Delegated handlers, NAMESPACED AND IDEMPOTENT: this.$ survives every
        // render() while on_ready() re-fires on each one, so one .off('.crta') here
        // clears this component's prior binds before they are re-attached. A one-shot
        // instance flag would be wrong in both directions - flags die with the
        // instance, handlers live on the element.
        this.$.off('.crta');

        if (!this.data.thread) return;

        // Open a document detail modal from any chip / sidebar card.
        this.$.on('click.crta', '[data-document]', async (e) => {
            const id = int($(e.currentTarget).data('document'));
            const document = this._find_document(id);
            if (!document) return;
            if (await Document_Detail_Modal.show(document)) this.reload();
        });

        // Open the participant contact card from either People_List (client members /
        // staff). The list hands back the exact person object, so no re-lookup; both
        // groups share one handler. Child components are recreated per render, so the
        // bind stays fresh across reload().
        this.$.find('.People_List').each((i, el) => {
            $(el).component().on('person_click', (c, d) => Request_Participant_Card_Modal.show(d.person));
        });

        if (this.data.thread.is_closed) return; // closed threads are read-only

        // Attach files: trigger the hidden input, upload to /_upload, stage the key.
        if (this.$sid('attach-btn').exists()) {
            this.$sid('attach-btn').click(() => this.$sid('reply-file-input').click());
            this.$sid('reply-file-input').on('change', async (e) => {
                const files = Array.from(e.currentTarget.files || []);
                for (const file of files) {
                    await this._upload_attachment(file);
                }
                this.$sid('reply-file-input').val('');
            });
        }

        // Send the reply (message + staged attachments + optional status change).
        if (this.$sid('send-btn').exists()) {
            this.$sid('send-btn').click(() => this._send_reply());
        }
    }

    /**
     * Find a document row (in either bucket or any message's chips) by id.
     */
    _find_document(id) {
        for (const doc of this.data.needs_review) {
            if (int(doc.id) === id) return doc;
        }
        for (const doc of this.data.accepted) {
            if (int(doc.id) === id) return doc;
        }
        for (const entry of this.data.timeline) {
            if (entry.type !== 'message') continue;
            for (const doc of (entry.documents || [])) {
                if (int(doc.id) === id) return doc;
            }
        }
        return null;
    }

    /**
     * Upload a single file through the framework upload transport and stage its key.
     * Re-renders the composer to show the staged-attachment chips.
     */
    async _upload_attachment(file) {
        this.$sid('attach-btn').prop('disabled', true);
        try {
            const form_data = new FormData();
            form_data.append('file', file);

            const result = await Ajax.upload(form_data);

            this.state.pending_attachments.push({
                key: result.attachment.key,
                name: file.name,
            });
            this._render_pending_attachments();
        } catch (err) {
            await Modal.alert('Upload Failed', err.message || 'Failed to upload file');
        } finally {
            this.$sid('attach-btn').prop('disabled', false);
        }
    }

    /**
     * Render the staged-attachment chips (with remove affordance) in the composer.
     */
    _render_pending_attachments() {
        const $container = this.$sid('pending-attachments');
        if (!$container.exists()) return;
        $container.empty();
        for (const attachment of this.state.pending_attachments) {
            const $chip = $('<span class="Clients_Request_Thread_Action__pending-chip badge bg-light text-dark"></span>');
            $chip.append($('<span></span>').text(attachment.name));
            const $remove = $('<i class="bi bi-x Clients_Request_Thread_Action__pending-remove" title="Remove"></i>');
            $remove.on('click', () => {
                this.state.pending_attachments = this.state.pending_attachments.filter((a) => a.key !== attachment.key);
                this._render_pending_attachments();
            });
            $chip.append($remove);
            $container.append($chip);
        }
    }

    /**
     * Post the reply: message body + staged attachment keys + optional status change.
     */
    async _send_reply() {
        const body = str(this.$sid('reply-body').val()).trim();
        const status = str(this.$sid('reply-status').val());
        const attachment_keys = this.state.pending_attachments.map((a) => a.key);

        if (body === '' && attachment_keys.length === 0 && status === '') {
            await Modal.alert('Nothing to Send', 'Enter a message, attach a file, or change the status.');
            return;
        }

        this.$sid('send-btn').prop('disabled', true);
        try {
            await Frontend_Clients_Controller.request_thread_reply({
                thread_id: this.args.thread_id,
                body,
                status: status !== '' ? int(status) : null,
                attachment_keys,
            });
            this.state.pending_attachments = [];
            this.reload();
        } catch (e) {
            await Modal.alert('Error', e.message || 'Failed to send reply');
            this.$sid('send-btn').prop('disabled', false);
        }
    }

    async page_title() {
        await this.await_loaded();
        return this.data.thread ? this.data.thread.title : 'Request';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.thread ? this.data.thread.client_name : 'Client';
    }

    async breadcrumb_label_active() {
        await this.await_loaded();
        return this.data.thread ? this.data.thread.title : 'Request';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Clients_View_Action', {id: this.args.id});
    }

    page_actions() {
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Clients_View_Action', this.args.id)}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to Client
                </a>
            </div>
        `;
    }
}
