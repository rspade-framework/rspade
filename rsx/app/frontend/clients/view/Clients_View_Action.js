/**
 * Client detail view action
 *
 * Displays detailed information about a single client, composed from the
 * semantic view-page vocabulary (Page_Scaffold / Card_Widget / Entity_Header /
 * Tab_Bar / Section / Detail_Sidebar).
 */
@route('/clients/view/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Clients_View_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        // Initialize client data to prevent undefined errors during first render
        this.data.client = {
            name: '',
            tags: [],
        };
        this.data.contacts = [];
        this.data.projects = [];
        this.data.activity = [];
        this.data.error_data = null;
        this.data.loading = true;

        // Canonical realtime pattern: subscribe in on_create() so the FIRST on_load() is
        // GATED on subscription establishment -> exactly ONE fetch, strictly after the
        // subscription is live (no fetch-then-resync duplicate; the initial resync is
        // swallowed, the gated load IS the revalidation). The client row, its contacts and
        // its projects all touch this client (#[Realtime_Touch] on the Contact/Project
        // client() belongsTo — including bulk builder writes), so this single Client
        // subscription covers every dependent tab. refresh() (not reload())
        // refetches via on_load() but re-renders ONLY if the data actually changed - a
        // no-visible-change notification repaints nothing. Idempotent per component, so the
        // re-fire of the lifecycle after a re-render does not stack callbacks.
        this.subscribe(Client_Model, this.args.id, () => this.refresh());
    }

    async on_load() {
        try {
            // The client record is loaded in two steps, because this screen serves deleted
            // clients too (it is where they are restored from). The ORM fetch serves
            // non-deleted records only, so a soft-deleted client reads as not-found there;
            // the deleted-record endpoint is asked next - gated on the server for exactly
            // that situation (the same gate as the restore it leads to), which is why that
            // lookup is a named endpoint and not a widened ORM fetch. A client that is
            // neither live nor deleted-and-visible keeps the original not-found error, and
            // any other error propagates untouched. The steps live inline because on_load()
            // may touch nothing but this.args and this.data.
            let not_found_error = null;

            try {
                this.data.client = await Client_Model.fetch(this.args.id);
            } catch (e) {
                if (e.code !== Ajax.ERROR_NOT_FOUND) throw e;
                not_found_error = e;
            }

            if (not_found_error) {
                const deleted_result = await Frontend_Clients_Controller.fetch_deleted({id: this.args.id});
                if (!deleted_result.client) throw not_found_error;

                this.data.client = deleted_result.client;
            }

            const [contacts_result, projects_result, activity_result] = await Promise.all([
                Frontend_Clients_Controller.client_contacts({id: this.args.id}),
                Frontend_Clients_Controller.client_projects({id: this.args.id}),
                Frontend_Clients_Controller.client_activity({id: this.args.id}),
            ]);
            this.data.contacts = contacts_result.contacts;
            this.data.projects = projects_result.projects;
            // Decorate each activity entry with its Feed_Row icon/variant (shared map).
            this.data.activity = activity_result.activity.map(a => ({...a, ...Activity_Feed.decorate(a.type_id)}));
        } catch (e) {
            this.data.error_data = e;
        }
        this.data.loading = false;
    }

    // Breadcrumb methods
    async page_title() {
        await this.await_loaded();
        return this.data.client.name || 'Client';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.client.name || 'Client';
    }

    async breadcrumb_label_active() {
        return 'View Client';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Clients_Index_Action');
    }

    on_ready() {
        // KPI cells that jump to a tab (Kpi_Cell $clickable $tab).
        this.$.on('click', '.Kpi_Cell--clickable', (e) => {
            const tab = $(e.currentTarget).attr('data-kpi-tab');
            const tab_bar = this.sid('tabs');
            if (tab && tab_bar) tab_bar.activate(tab);
        });

        if (this.$sid('enable-portal').exists()) {
            this.$sid('enable-portal').click(async () => {
                await Frontend_Clients_Controller.toggle_portal({id: this.args.id, enabled: true});
                this.reload();
            });
        }

        if (this.$sid('disable-portal').exists()) {
            this.$sid('disable-portal').click(async () => {
                const confirmed = await Modal.confirm('Disable Portal', 'Are you sure you want to disable the portal for this client?\n\nExisting portal members will lose access.', 'Disable', 'Cancel');
                if (!confirmed) return;
                await Frontend_Clients_Controller.toggle_portal({id: this.args.id, enabled: false});
                this.reload();
            });
        }

        if (this.$sid('delete-client').exists()) {
            this.$sid('delete-client').click(async () => {
                const confirmed = await Modal.confirm('Delete Client', 'Are you sure you want to delete this client?\n\nThis can be undone by restoring the client afterward.', 'Delete', 'Cancel');
                if (!confirmed) return;
                await Frontend_Clients_Controller.delete({id: this.args.id});
                Spa.dispatch(Rsx.Route('Clients_Index_Action'));
            });
        }

        if (this.$sid('restore-client').exists()) {
            this.$sid('restore-client').click(async () => {
                await Frontend_Clients_Controller.restore({id: this.args.id});
                this.reload();
            });
        }
    }

    // Action buttons for the page header (Back / Edit)
    page_actions() {
        const id = this.args.id;
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Clients_Index_Action')}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                ${id ? `
                <a href="${Rsx.Route('Clients_Edit_Action', id)}" class="btn btn-primary btn-sm">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                ` : ''}
            </div>
        `;
    }
}
