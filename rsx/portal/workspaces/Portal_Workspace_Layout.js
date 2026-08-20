/**
 * Portal_Workspace_Layout - sublayout for the per-client workspace area.
 *
 * The portal analogue of the staff Settings_Layout: a persistent shell wrapped around
 * the workspace tabs (Overview / Requests / Documents). It provides:
 *   - a persistent header showing the engagement (client) name + the caller's role
 *   - a left vertical pills nav linking to each tab for the CURRENT workspace :id
 *   - a $sid="content" area where the per-tab sub-action renders
 *
 * Used as a nested layout inside Portal_Layout:
 *   @layout('Portal_Layout')
 *   @layout('Portal_Workspace_Layout')
 *   class Portal_Workspace_Overview_Action extends Spa_Action { }
 *
 * How the parametrized sublayout gets the :id (the key mechanism):
 *   Spa reuses a layout instance by class name across navigations, so this single
 *   instance persists across Overview -> Requests -> Documents AND across different
 *   workspace ids. The layout therefore does NOT receive args at construction; it
 *   reads the route :id from on_action(url, action_name, args) on every dispatch.
 *
 *   on_action fires AFTER Spa has already mounted the sub-action into $content, so the
 *   layout must NOT call render() (that would wipe the just-mounted action). Instead it
 *   updates the persistent header + pill hrefs via direct DOM writes (mirroring how
 *   Settings_Layout updates only its nav state in on_action). The header is loaded once
 *   per workspace and re-loaded only when the :id changes.
 *
 * Membership gate: each tab action runs its own three-state gate off
 * Portal_Workspaces_Controller.get(), which fails closed for a non-member - so a
 * non-member hitting ANY tab lands on the access error. This layout's header load uses
 * the same fail-closed endpoint; on failure it simply leaves the header blank (the
 * error itself is shown by the tab body).
 */
class Portal_Workspace_Layout extends Spa_Layout {

    // Map action class names to a pill identifier for active-state tracking.
    static NAV_CONFIG = {
        Portal_Workspace_Overview_Action: 'overview',
        Portal_Workspace_Requests_Action: 'requests',
        Portal_Request_Thread_Action: 'requests',
        Portal_Workspace_Documents_Action: 'documents',
    };

    on_create() {
        this.state = {
            // The workspace id whose header is currently loaded (null until loaded).
            loaded_id: null,
            // The active pill identifier for the current action.
            active_page: null,
        };
    }

    async on_action(url, action_name, args) {
        this.state.active_page = Portal_Workspace_Layout.NAV_CONFIG[action_name] || null;

        // Scaffolded reconciliation (semantic view-page refactor, Batch J). A workspace
        // tab action composing with Page_Scaffold declares `scaffolded = true`; stamp the
        // content pane so the nested scaffold's --page-pad collapses to 0 and it fills the
        // pane (the workspace `.container`/`.row` already frames it, and the scaffold's
        // 2300px max-width never binds inside the narrower content track). BOTH layers in
        // the chain react (Portal_Layout zeroes the outer padding). Mirrors Settings_Layout
        // / System_Layout; reset per navigation so an unconverted tab restores the default.
        this.$content().toggleClass('Portal_Workspace_Layout__content--scaffolded', !!this.action.scaffolded);

        const id = args && args.id ? int(args.id) : 0;

        // Point the pills at this workspace and reflect the active tab. Cheap, do it
        // every dispatch so a workspace switch updates the nav immediately.
        this._update_pill_hrefs(id);
        this._update_active_nav();

        // Load (or re-load) the header only when the workspace id changes. The same
        // header persists while the user moves between tabs of the same workspace.
        if (id !== this.state.loaded_id) {
            this.state.loaded_id = id;
            this._set_header({ name: '', role_label: '' });

            try {
                const workspace = await Portal_Workspaces_Controller.get({ id });
                // Guard against a race: a faster workspace switch may have superseded us.
                if (this.state.loaded_id === id) {
                    this._set_header(workspace);
                }
            } catch (e) {
                // Non-member / not-found: the tab body shows the access error; the
                // header just stays blank.
            }
        }
    }

    on_ready() {
        this._update_active_nav();
    }

    /**
     * Write the client name + role badge into the persistent header.
     */
    _set_header(workspace) {
        if (typeof this.$sid !== 'function') {
            return;
        }

        this.$sid('title').text(workspace.name || '');

        if (workspace.role_label) {
            this.$sid('role').text(workspace.role_label);
            this.$sid('role-wrap').removeClass('d-none');
        } else {
            this.$sid('role').text('');
            this.$sid('role-wrap').addClass('d-none');
        }
    }

    /**
     * Point each pill at the given workspace id.
     */
    _update_pill_hrefs(id) {
        if (!this.$ || !this.$.find) {
            return;
        }

        this.$.find('.Portal_Workspace_Layout__pill[data-page="overview"]').attr('href', Rsx_Portal.Route('Portal_Workspace_Overview_Action', id));
        this.$.find('.Portal_Workspace_Layout__pill[data-page="requests"]').attr('href', Rsx_Portal.Route('Portal_Workspace_Requests_Action', id));
        this.$.find('.Portal_Workspace_Layout__pill[data-page="documents"]').attr('href', Rsx_Portal.Route('Portal_Workspace_Documents_Action', id));
    }

    /**
     * Reflect the active pill in the nav.
     */
    _update_active_nav() {
        if (!this.$ || !this.$.find) {
            return;
        }

        this.$.find('.Portal_Workspace_Layout__pill').removeClass('active');

        if (this.state.active_page) {
            this.$.find(`.Portal_Workspace_Layout__pill[data-page="${this.state.active_page}"]`).addClass('active');
        }
    }
}
