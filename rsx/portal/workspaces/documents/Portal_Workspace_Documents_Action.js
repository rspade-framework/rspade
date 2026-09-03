/**
 * Portal_Workspace_Documents_Action - the Documents tab (/workspace/:id/documents).
 *
 * Lists the documents the firm has SHARED with the logged-in portal user for this
 * workspace (client). Each document renders as a card: thumbnail preview + name +
 * who shared it / when (+ optional message) + a Download button.
 *
 * Three-state, membership-gated: the membership gate runs server-side in
 * Portal_Documents_Controller.list() (fail-closed via has_client_access), which also
 * scopes results to the caller's own contact - never to a client-supplied id. A
 * non-member / wrong-contact caller gets an unauthorized error (shown via the error
 * state) or an empty list.
 */
@route('/workspace/:id/documents')
@layout('Portal_Layout')
@layout('Portal_Workspace_Layout')
@portal_spa('Portal_Spa_Controller::index')
@title('Documents')
@auth('is_logged_in')
class Portal_Workspace_Documents_Action extends Spa_Action {
    // Composes with Page_Scaffold (the workspace sublayout yields --page-pad: 0).
    scaffolded = true;

    on_create() {
        this.data.documents = [];
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            // Both calls key off this.args.id and BOTH enforce the membership gate
            // themselves (Portal_Permission::has_client_access, fail-closed), so the
            // header lookup is not a precondition for the list - it is an independent
            // fetch whose result is simply discarded here. Neither is optional: a
            // non-member must see the error, so neither branch carries a .catch().
            const [, result] = await Promise.all([
                Portal_Workspaces_Controller.get({ id: this.args.id }),
                Portal_Documents_Controller.list({ client_id: this.args.id }),
            ]);
            this.data.documents = result.documents;
        } catch (e) {
            this.data.error_data = e;
        }
        this.data.loading = false;
    }
}
