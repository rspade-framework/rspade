/**
 * Approvals - the queue of SUBMITTED entries an approver works through, oldest first, with
 * Approve and Reject on each row.
 *
 * Gated like the approve and reject endpoints themselves (can_edit_data + can_approve_billing),
 * so its nav entry and every Approve / Reject button elsewhere (Billing_Entry_Actions.can_approve())
 * show exactly to the people the server lets act.
 */
@route('/billing/approvals')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Approvals')
@auth('is_logged_in', 'can_view_data', 'can_edit_data', 'can_approve_billing')
class Billing_Approvals_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    async breadcrumb_label() {
        return 'Approvals';
    }

    async breadcrumb_label_active() {
        return 'Entries waiting for approval';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Billing_Index_Action');
    }
}
