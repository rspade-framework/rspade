/**
 * Portal_Workspace_Overview_Action - the default workspace tab (/workspace/:id).
 *
 * Renders into Portal_Workspace_Layout's content area. Shows a short summary plus a
 * few key client "vitals" (demo/placeholder data the controller derives from
 * portal-safe client fields). Three-state pattern (loading -> error -> content): the
 * error state is the membership gate - get() fails closed for a non-member, so a
 * non-member lands on Universal_Error_Page_Component here as well as in the persistent
 * header.
 */
@route('/workspace/:id')
@layout('Portal_Layout')
@layout('Portal_Workspace_Layout')
@portal_spa('Portal_Spa_Controller::index')
@auth('is_logged_in')
class Portal_Workspace_Overview_Action extends Spa_Action {
    // Composes with Page_Scaffold; the workspace sublayout + Portal_Layout yield
    // width/padding to it (--page-pad: 0 on the workspace content pane).
    scaffolded = true;

    on_create() {
        this.data.workspace = { name: '', role_label: '', vitals: {} };
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            this.data.workspace = await Portal_Workspaces_Controller.get({ id: this.args.id });
        } catch (e) {
            this.data.error_data = e;
        }
        this.data.loading = false;
    }

    async page_title() {
        await this.await_loaded();
        return this.data.workspace.name || 'Workspace';
    }
}
