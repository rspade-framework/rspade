/**
 * Portal_Request_Thread_Action - the client (portal) view of a single request thread.
 *
 * The portal mirror of the staff Clients_Request_Thread_Action. Two-column layout:
 * LEFT = the timeline (messages as chat cards, status events as centered cards) followed
 * by a reply composer (message + Attach Files + Send), shown ONLY when the thread is not
 * closed AND the caller can collaborate (viewers get a read-only note); RIGHT = vitals
 * (title, portal status badge, participants) + the Awaiting Review and Accepted document
 * buckets. Clicking any document opens the Portal_Document_Detail_Modal (download +
 * read-only review state - NO accept/reject on the portal).
 *
 * Three-state (loading / error / content). on_load fetches Portal_Request_Threads_Controller.get;
 * a reply posts via .reply then reload()s.
 */
@route('/workspace/:id/requests/:thread_id')
@layout('Portal_Layout')
@layout('Portal_Workspace_Layout')
@portal_spa('Portal_Spa_Controller::index')
@auth('is_logged_in')
class Portal_Request_Thread_Action extends Spa_Action {
    // Composes with Page_Scaffold (the workspace sublayout yields --page-pad: 0).
    scaffolded = true;

    on_create() {
        this.data.thread = null;
        this.data.timeline = [];
        this.data.participants = { members: [], staff: [] };
        this.data.needs_review = [];
        this.data.accepted = [];
        this.data.error_data = null;
        this.data.loading = true;
        // Pending attachment keys (uploaded but not yet sent with a reply). UI-only.
        this.state.pending_attachments = [];
    }

    async on_load() {
        try {
            // Membership gate + workspace header (fails closed for a non-member).
            await Portal_Workspaces_Controller.get({ id: this.args.id });

            const result = await Portal_Request_Threads_Controller.get({ id: this.args.thread_id });
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
        if (!this.data.thread) return;

        // Open the document detail modal from any chip / sidebar card. Namespaced +
        // idempotent (on_ready re-fires after each reload()).
        this.$.off('click.doc').on('click.doc', '[data-document]', async (e) => {
            const id = int($(e.currentTarget).data('document'));
            const document = this._find_document(id);
            if (!document) return;
            await Portal_Document_Detail_Modal.show(document);
        });

        // Open the participant contact card from either People_List (client members /
        // staff). The list hands back the exact person object, so no re-lookup. Both
        // groups share one handler; child components are recreated per render, so the
        // bind stays fresh across reload().
        this.$.find('.People_List').each((i, el) => {
            $(el).component().on('person_click', (c, d) => Portal_Participant_Card_Modal.show(d.person));
        });

        // The composer only renders for a non-closed thread the caller can collaborate on.
        if (!this.$sid('send-btn').exists()) return;

        // Attach files: trigger the hidden input, upload to /_upload, stage the key.
        this.$sid('attach-btn').click(() => this.$sid('reply-file-input').click());
        this.$sid('reply-file-input').on('change', async (e) => {
            const files = Array.from(e.currentTarget.files || []);
            for (const file of files) {
                await this._upload_attachment(file);
            }
            this.$sid('reply-file-input').val('');
        });

        // Send the reply (message + staged attachments).
        this.$sid('send-btn').click(() => this._send_reply());
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
     */
    async _upload_attachment(file) {
        this.$sid('attach-btn').prop('disabled', true);
        try {
            // site_id is derived server-side (Portal_Session) - never sent by the client.
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
            const $chip = $('<span class="Portal_Request_Thread_Action__pending-chip badge bg-light text-dark"></span>');
            $chip.append($('<span></span>').text(attachment.name));
            const $remove = $('<i class="bi bi-x Portal_Request_Thread_Action__pending-remove" title="Remove"></i>');
            $remove.on('click', () => {
                this.state.pending_attachments = this.state.pending_attachments.filter((a) => a.key !== attachment.key);
                this._render_pending_attachments();
            });
            $chip.append($remove);
            $container.append($chip);
        }
    }

    /**
     * Post the reply: message body + staged attachment keys. The body may be empty only
     * when at least one file is staged.
     */
    async _send_reply() {
        const body = str(this.$sid('reply-body').val()).trim();
        const attachment_keys = this.state.pending_attachments.map((a) => a.key);

        if (body === '' && attachment_keys.length === 0) {
            await Modal.alert('Nothing to Send', 'Enter a message or attach a file.');
            return;
        }

        this.$sid('send-btn').prop('disabled', true);
        try {
            await Portal_Request_Threads_Controller.reply({
                thread_id: this.args.thread_id,
                body,
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
}
