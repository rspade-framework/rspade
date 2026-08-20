/**
 * Clients_Portal_Panel - the client-portal management surface, folded into the
 * Clients_View "Portal" tab (D3). Loads the client's portal members, documents,
 * request threads, announcements and visible-project state, and drives every
 * portal-management flow (add/disable member, resend/cancel invite, change role,
 * upload/share/delete documents, post announcements, create requests, toggle
 * project visibility).
 *
 * A complex child Component (not a Spa_Action): on_load fetches its own data keyed
 * by $client_id; a mutation calls reload(). Rendered only when the client's portal
 * is enabled (the tab is absent otherwise).
 *
 * Args: $client_id (number, required), $site_id (number, for document uploads).
 */
class Clients_Portal_Panel extends Component {
    on_create() {
        this.data.members = [];
        this.data.pending_invites = [];
        this.data.projects = [];
        this.data.announcements = [];
        this.data.documents = [];
        this.data.deleted_documents = [];
        this.data.threads_active = [];
        this.data.threads_closed = [];
        this.data.threads_needs_review_total = 0;
        this.data.error_data = null;
        this.data.loading = true;

        // Canonical realtime pattern: subscribe in on_create() so the FIRST on_load() is
        // GATED on establishment -> a single race-free fetch (the initial resync is
        // swallowed, the gated load IS the revalidation). Same topic+filter as the parent
        // Clients_View_Action (Client_Model + this client id); the ref-counter collapses
        // both watchers into ONE server-side subscription. A portal reply cascades
        // message -> thread -> client, so this panel's request threads refresh live too.
        // refresh() re-renders only if the panel data changed.
        this.subscribe(Client_Model, this.args.client_id, () => this.refresh());
    }

    async on_load() {
        try {
            const [members_result, projects_result, announcements_result, documents_result, deleted_documents_result, threads_result] = await Promise.all([
                Frontend_Clients_Controller.portal_members({id: this.args.client_id}),
                Frontend_Clients_Controller.portal_projects({id: this.args.client_id}),
                Frontend_Clients_Controller.portal_announcements({id: this.args.client_id}),
                Frontend_Clients_Controller.documents_list({id: this.args.client_id}),
                Frontend_Clients_Controller.documents_deleted_list({id: this.args.client_id}),
                Frontend_Clients_Controller.request_threads_list({id: this.args.client_id}),
            ]);
            this.data.members = members_result.members;
            this.data.pending_invites = members_result.pending_invites;
            this.data.projects = projects_result.projects;
            this.data.announcements = announcements_result.announcements;
            this.data.documents = documents_result.documents;
            this.data.deleted_documents = deleted_documents_result.deleted_documents;
            this.data.threads_active = threads_result.active;
            this.data.threads_closed = threads_result.closed;
            this.data.threads_needs_review_total = threads_result.needs_review_total;
        } catch (e) {
            this.data.error_data = e;
        }
        this.data.loading = false;
    }

    on_ready() {
        // Re-bind idempotently: clear THIS component's namespaced delegated handlers from
        // any prior render before re-attaching, so a reload() cannot stack duplicate
        // handlers. Delegated handlers use the '.cpp' namespace; $sid handlers .off() first.
        this.$.off('.cpp');

        // Disable Access = remove this client's membership (account/login persist).
        this.$.on('click.cpp', '[data-disable-access]', async (e) => {
            const membership_id = $(e.currentTarget).data('disable-access');
            const confirmed = await Modal.confirm(
                'Disable Access',
                'Remove this member\'s access to this client?\n\nTheir portal account and login are unaffected - only their access to this client is removed.',
                'Disable Access',
                'Cancel'
            );
            if (!confirmed) return;

            await Frontend_Clients_Controller.portal_remove_member({membership_id});
            this.reload();
        });

        // Resend a pending invitation. Shows the invitation link (copyable, so it can
        // be handed to the contact directly) with the option to re-send by email.
        this.$.on('click.cpp', '[data-resend-invite]', async (e) => {
            const contact_id = int($(e.currentTarget).data('resend-invite'));
            const invite = (this.data.pending_invites || []).find((pi) => int(pi.contact_id) === contact_id);
            const company = this.args.client_name || '';
            const url = invite ? invite.url : '';
            const invite_message = `You've been invited to ${company}'s client portal. Get started here: ${url}`;

            const body = $('<div>').component('Resend_Invite_Modal_Body', { invite_message }).component();
            await body.ready();

            const resend = await Modal.show({
                title: 'Resend Invitation',
                body: body.$,
                max_width: 600,
                buttons: [
                    { label: 'Close', value: false, class: 'btn-secondary' },
                    { label: 'Resend Invite by Email', value: true, class: 'btn-primary', default: true },
                ],
            });

            if (!resend) return;

            try {
                const response = await Frontend_Clients_Controller.portal_resend_invite({
                    client_id: this.args.client_id,
                    contact_id,
                });
                await Modal.alert('Invitation Re-sent', response.message);
            } catch (err) {
                await Modal.alert('Error', err.message || 'Failed to resend invitation');
            }
            this.reload();
        });

        // Cancel (revoke) a pending invitation so its link stops working.
        this.$.on('click.cpp', '[data-revoke-invite]', async (e) => {
            const invitation_id = int($(e.currentTarget).data('revoke-invite'));

            const confirmed = await Modal.confirm(
                'Cancel Invitation',
                'Cancel this pending invitation?\n\nThe invite link will stop working.',
                'Cancel Invitation',
                'Keep',
            );
            if (!confirmed) return;

            try {
                await Frontend_Clients_Controller.portal_revoke_invite({ invitation_id });
            } catch (err) {
                await Modal.alert('Error', err.message || 'Failed to cancel invitation');
            }
            this.reload();
        });

        // Change member role
        this.$.on('change.cpp', '[data-role-membership]', async (e) => {
            const $select = $(e.currentTarget);
            const membership_id = $select.data('role-membership');
            const role_id = int($select.val());
            await Frontend_Clients_Controller.portal_update_role({membership_id, role_id});
        });

        // Add member button -> the redesigned checkbox-table modal.
        if (this.$sid('add-member-btn').exists()) {
            this.$sid('add-member-btn').off('click').click(async () => {
                if (await Add_Portal_Members_Modal.show(this.args.client_id)) this.reload();
            });
        }

        // Toggle project visibility
        this.$.on('change.cpp', '[data-toggle-project]', async (e) => {
            const project_id = $(e.currentTarget).data('toggle-project');
            await Frontend_Clients_Controller.portal_toggle_project({
                client_id: this.args.client_id,
                project_id: project_id,
            });
        });

        // Post announcement
        if (this.$sid('announcement-post-btn').exists()) {
            this.$sid('announcement-post-btn').off('click').click(() => this._post_announcement());
        }

        // --- Documents ---

        // Upload Document: trigger the hidden file input.
        if (this.$sid('upload-doc-btn').exists()) {
            this.$sid('upload-doc-btn').off('click').click(() => this.$sid('doc-file-input').click());
            this.$sid('doc-file-input').off('change').on('change', (e) => {
                const file = e.currentTarget.files[0];
                if (file) this._upload_document(file);
            });
        }

        // Share a document with the client's members (add/remove via the share modal).
        this.$.on('click.cpp', '[data-share-doc]', async (e) => {
            const attachment_id = int($(e.currentTarget).data('share-doc'));
            const doc = (this.data.documents || []).find((d) => int(d.attachment_id) === attachment_id);
            const shared_ids = doc ? (doc.shared_with || []).map((s) => s.contact_id) : [];
            if (await Share_Document_Modal.show(this.args.client_id, attachment_id, shared_ids)) this.reload();
        });

        // Delete a document (detaches it and removes all shares).
        this.$.on('click.cpp', '[data-delete-doc]', async (e) => {
            const attachment_id = int($(e.currentTarget).data('delete-doc'));
            const confirmed = await Modal.confirm(
                'Delete Document',
                'Delete this document?\n\nIt will be removed from the client and any contacts it was shared with will lose access.',
                'Delete',
                'Cancel'
            );
            if (!confirmed) return;
            await Frontend_Clients_Controller.document_delete({client_id: this.args.client_id, attachment_id});
            this.reload();
        });

        // Restore a recently-deleted document out of the retention window (comes back
        // UNshared - re-share as needed). Mirrors the delete handler above.
        this.$.on('click.cpp', '[data-restore-doc]', async (e) => {
            const attachment_id = int($(e.currentTarget).data('restore-doc'));
            const confirmed = await Modal.confirm(
                'Restore Document',
                'Restore this document?\n\nIt returns to the client\'s documents. It will not be re-shared with any contacts - share it again if needed.',
                'Restore',
                'Cancel'
            );
            if (!confirmed) return;
            try {
                await Frontend_Clients_Controller.document_restore({client_id: this.args.client_id, attachment_id});
            } catch (err) {
                await Modal.alert('Cannot Restore', err.message || 'This document can no longer be restored.');
            }
            this.reload();
        });

        // --- Request threads ---

        // New Request: title + body -> request_thread_create, then open the new thread.
        if (this.$sid('new-request-btn').exists()) {
            this.$sid('new-request-btn').off('click').click(async () => {
                const redirect = await New_Request_Thread_Modal.show(this.args.client_id);
                if (redirect) Spa.dispatch(redirect);
            });
        }
    }

    /**
     * Upload a file through the framework upload transport, then attach it to this
     * client as a document via documents_add (a type-safe Ajax endpoint).
     */
    async _upload_document(file) {
        this.$sid('upload-doc-btn').prop('disabled', true);
        try {
            // site_id is derived server-side from the session - never sent by the client.
            const form_data = new FormData();
            form_data.append('file', file);

            const result = await Ajax.upload(form_data);

            await Frontend_Clients_Controller.documents_add({
                client_id: this.args.client_id,
                key: result.attachment.key,
            });

            this.reload();
        } catch (err) {
            await Modal.alert('Upload Failed', err.message || 'Failed to upload document');
        } finally {
            this.$sid('upload-doc-btn').prop('disabled', false);
            this.$sid('doc-file-input').val('');
        }
    }

    async _post_announcement() {
        const title = str(this.$sid('announcement-title').val()).trim();
        const body = str(this.$sid('announcement-body').val()).trim();

        if (!title || !body) {
            await Modal.alert('Missing Fields', 'Please enter both a title and a message.');
            return;
        }

        const member_count = this.data.members.length;
        const confirmed = await Modal.confirm(
            'Post Announcement',
            `Send "${title}" to ${member_count} portal ${member_count === 1 ? 'member' : 'members'}?\n\nIt will appear in their portal activity feed.`,
            'Post',
            'Cancel'
        );
        if (!confirmed) return;

        try {
            const response = await Frontend_Clients_Controller.portal_post_announcement({
                client_id: this.args.client_id,
                title: title,
                body: body,
            });

            await Modal.alert(
                'Announcement Posted',
                `Delivered to ${response.recipients} portal ${response.recipients === 1 ? 'member' : 'members'}.`
            );

            this.reload();
        } catch (e) {
            await Modal.alert('Error', e.message || 'Failed to post announcement');
        }
    }
}
