/**
 * Billing_Entry_Actions - the approver's two decisions on a SUBMITTED entry, shared by the
 * entry's view page and the approvals queue.
 *
 * Each answers true when the entry was decided, false when the user backed out. A refusal the
 * server answers (the entry was decided by somebody else meanwhile) is not caught: it reaches
 * the application's Ajax error dialog like any other.
 *
 * Who sees the buttons: can_approve() - the approvals screen's own gates, which are the
 * approve and reject endpoints' gates (can_edit_data + can_approve_billing).
 */
class Billing_Entry_Actions {
    /** May the signed-in user approve and reject entries? */
    static can_approve() {
        return Permission.can_access('Billing_Approvals_Action');
    }

    /**
     * Confirm, then approve. $action is the caller's Ajax_Action for this control, so a retry
     * after a lost answer replays instead of approving twice.
     *
     * @param {number} id
     * @param {Ajax_Action} action
     * @returns {Promise<boolean>}
     */
    static async approve(id, action) {
        const confirmed = await Modal.confirm(
            'Approve Entry',
            'Approve this entry?\n\nIts amount becomes final and it can be invoiced.',
            'Approve'
        );
        if (!confirmed) {
            return false;
        }

        await action.call(Frontend_Billing_Controller.approve, { id: id });

        return true;
    }

    /**
     * Ask for the reason and reject - Billing_Reject_Form submits to the reject endpoint,
     * which refuses a blank reason as a field error.
     *
     * @param {number} id
     * @returns {Promise<boolean>}
     */
    static async reject(id) {
        const result = await Modal.form({
            title: 'Reject Entry',
            component: 'Billing_Reject_Form',
            component_args: { id: id },
            submit_label: 'Reject',
            max_width: 560,
        });

        return !!result;
    }
}
