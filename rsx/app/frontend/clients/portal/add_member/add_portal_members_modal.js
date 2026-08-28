/**
 * Add_Portal_Members_Modal
 *
 * Orchestrates the Add-Member flow:
 *   1. Fetch the client's contacts + invite status.
 *   2. Show the checkbox-table modal (Add_Portal_Members_Modal_Form).
 *   3. On "Invite Contacts", close that modal and open a confirmation modal listing
 *      the selected invitees -> Confirm / Cancel.
 *   4. Confirm performs the bulk invite and reports per-bucket counts.
 *
 * Returns true if invitations were sent (caller should reload), false otherwise.
 */
class Add_Portal_Members_Modal extends Modal_Abstract {
    /**
     * @param {number} client_id
     * @returns {Promise<boolean>} true if any invitations were sent
     */
    static async show(client_id) {
        const result = await Frontend_Clients_Controller.portal_contacts_with_status({ id: client_id });
        const contacts = result.contacts;

        if (contacts.length === 0) {
            await Modal.alert('No Contacts', 'This client has no active contacts to invite. Add a contact first.');
            return false;
        }

        // Step 1: the selection table. This dialog SUBMITS NOTHING - it collects a
        // choice and hands it back, and this class performs the invite below. That
        // makes it Modal.show, not Modal.form: there is no form and no endpoint for a
        // form to name.
        const $body = $('<div>');
        let body = null;

        const selection = await Modal.show({
            title: 'Add Portal Members',
            body: $body,
            max_width: 720,
            buttons: [
                { label: 'Cancel', value: false, class: 'btn-secondary' },
                {
                    label: 'Invite Contacts',
                    class: 'btn-primary',
                    default: true,
                    callback: () => {
                        const contact_ids = body.get_selected_contact_ids();
                        if (contact_ids.length === 0) {
                            // Only a literal false keeps the dialog open.
                            body.show_empty_selection_note();
                            return false;
                        }
                        return contact_ids;
                    },
                },
            ],
            on_show: function () {
                body = $body.component('Add_Portal_Members_Modal_Form', { contacts }).component();
            },
        });

        if (!selection || selection.length === 0) {
            return false;
        }

        // Step 2: confirmation listing the chosen invitees.
        const by_id = {};
        for (const c of contacts) by_id[c.id] = c;
        const lines = selection.map((id) => {
            const c = by_id[id];
            return `• ${c.full_name} (${c.email})`;
        });

        const confirmed = await Modal.confirm(
            'Confirm Invitations',
            `Invite the following ${selection.length === 1 ? 'contact' : selection.length + ' contacts'} to this portal?\n\n${lines.join('\n')}`,
            'Send Invitations',
            'Cancel'
        );
        if (!confirmed) return false;

        // Step 3: perform the bulk invite.
        try {
            const response = await Frontend_Clients_Controller.portal_bulk_invite({
                client_id,
                contact_ids: selection,
                role_id: Portal_Membership_Model.ROLE_VIEWER,
            });

            await Modal.alert('Invitations Sent', Add_Portal_Members_Modal._summary(response));
            return (response.invited_new + response.invited_existing) > 0;
        } catch (e) {
            await Modal.alert('Error', e.message || 'Failed to send invitations');
            return false;
        }
    }

    /**
     * Human-readable summary of the bulk-invite result.
     *
     * @param {{invited_new:number, invited_existing:number, skipped:number}} response
     * @returns {string}
     */
    static _summary(response) {
        const parts = [];
        if (response.invited_new > 0) {
            parts.push(`${response.invited_new} new ${response.invited_new === 1 ? 'invitation' : 'invitations'} emailed`);
        }
        if (response.invited_existing > 0) {
            parts.push(`${response.invited_existing} pending ${response.invited_existing === 1 ? 'invitation' : 'invitations'} sent to existing accounts`);
        }
        if (response.skipped > 0) {
            parts.push(`${response.skipped} skipped (already members or already invited)`);
        }
        return parts.length ? parts.join('\n') : 'No changes.';
    }
}
