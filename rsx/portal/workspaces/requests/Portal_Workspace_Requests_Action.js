/**
 * Portal_Workspace_Requests_Action - the Requests tab (/workspace/:id/requests).
 *
 * A LIST of request threads (firm <-> client conversations) for this workspace, split
 * into an Active group (status != Closed) and a Closed group. Each row shows the thread
 * title, its portal status badge, message count and last activity; clicking a row opens
 * the portal thread view (/workspace/:id/requests/:thread_id).
 *
 * Three-state, membership-gated: the membership gate + workspace header run server-side
 * via Portal_Workspaces_Controller.get() (fail-closed for a non-member); the list itself
 * comes from Portal_Request_Threads_Controller.list() (also fail-closed).
 */
@route('/workspace/:id/requests')
@layout('Portal_Layout')
@layout('Portal_Workspace_Layout')
@portal_spa('Portal_Spa_Controller::index')
@title('Requests')
@auth('is_logged_in')
class Portal_Workspace_Requests_Action extends Spa_Action {
    // Composes with Page_Scaffold (the workspace sublayout yields --page-pad: 0).
    scaffolded = true;

    on_create() {
        this.data.active = [];
        this.data.closed = [];
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
                Portal_Request_Threads_Controller.list({ client_id: this.args.id }),
            ]);
            this.data.active = result.active;
            this.data.closed = result.closed;
        } catch (e) {
            this.data.error_data = e;
        }
        this.data.loading = false;
    }
}
