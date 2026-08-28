/**
 * Share_Document_Modal
 *
 * Manage who a client document is shared with. The picker shows ALL of the client's
 * contacts as checkboxes, pre-checked for those the document is already shared with. On
 * submit it diffs the selection against the current shares and, if anything changed,
 * shows a confirmation summarizing who will be added / sent invites and whose access
 * will be removed, then applies the change as a single set-based documents_share call.
 *
 * Returns true if a change was applied (caller should reload), false otherwise.
 */
class Share_Document_Modal extends Modal_Abstract {
    /**
     * @param {number} client_id
     * @param {number} attachment_id
     * @param {number[]} shared_contact_ids  contact ids the document is currently shared with
     * @returns {Promise<boolean>} true if a sharing change was applied
     */
    static async show(client_id, attachment_id, shared_contact_ids = []) {
        const result = await Frontend_Clients_Controller.portal_contacts_with_status({ id: client_id });
        const contacts = result.contacts;

        if (contacts.length === 0) {
            await Modal.alert('No Contacts', 'This client has no active contacts to share with. Add a contact first.');
            return false;
        }

        const shared_set = new Set(shared_contact_ids.map((id) => int(id)));
        contacts.forEach((c) => { c.currently_shared = shared_set.has(int(c.id)); });

        // The checkbox table (pre-checked for current shares) + optional message. This
        // dialog SUBMITS NOTHING: it reports a selection, this class diffs it, confirms
        // it, and then makes the one set-based call. No form, no endpoint - so
        // Modal.show, not Modal.form.
        const $body = $('<div>');
        let body = null;

        const selection = await Modal.show({
            title: 'Share Document',
            body: $body,
            max_width: 720,
            buttons: [
                { label: 'Cancel', value: false, class: 'btn-secondary' },
                {
                    label: 'Review changes',
                    class: 'btn-primary',
                    default: true,
                    callback: () => body.get_selection(), // {contact_ids, message}
                },
            ],
            on_show: function () {
                body = $body.component('Share_Document_Modal_Form', { contacts }).component();
            },
        });

        if (!selection) {
            return false; // cancelled
        }

        // Diff the selection against the current shares.
        const selected = new Set(selection.contact_ids.map((id) => int(id)));
        const added = [...selected].filter((id) => !shared_set.has(id));
        const removed = [...shared_set].filter((id) => !selected.has(id));

        if (added.length === 0 && removed.length === 0) {
            return false; // no changes -> no confirmation, nothing to do
        }

        const label_of = (id) => {
            const c = contacts.find((x) => int(x.id) === int(id));
            if (!c) return `#${id}`;
            return c.email ? `${c.full_name} (${c.email})` : c.full_name;
        };

        // Confirmation: two bulleted groups separated by a blank line; an empty group is
        // omitted entirely.
        const blocks = [];
        if (added.length) {
            blocks.push('These members will be added / sent invites:\n' + added.map((id) => `• ${label_of(id)}`).join('\n'));
        }
        if (removed.length) {
            blocks.push('These members will be removed:\n' + removed.map((id) => `• ${label_of(id)}`).join('\n'));
        }

        const confirmed = await Modal.confirm('Confirm Sharing Changes', blocks.join('\n\n'), 'Apply Changes', 'Cancel');
        if (!confirmed) {
            return false;
        }

        try {
            await Frontend_Clients_Controller.documents_share({
                client_id,
                attachment_id,
                contact_ids: selection.contact_ids,
                message: selection.message,
            });
            return true;
        } catch (e) {
            await Modal.alert('Error', e.message || 'Failed to update sharing');
            return false;
        }
    }
}
