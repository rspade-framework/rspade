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

        // Step 1: the selection table. on_submit returns the selected contact ids
        // (or null to keep the modal open if nothing is selected).
        const selection = await Modal.form({
            title: 'Add Portal Members',
            component: 'Add_Portal_Members_Modal_Form',
            component_args: { contacts },
            submit_label: 'Invite Contacts',
            max_width: 720,
            on_submit: (form) => {
                const vals = form.vals();
                if (vals.contact_ids.length === 0) {
                    Modal.alert('Nothing Selected', 'Select at least one contact to invite.');
                    return null; // keep open
                }
                return vals.contact_ids; // close + return ids
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
