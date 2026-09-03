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
            // ONE parallel batch. Every call keys off this.args.id, so nothing here
            // depends on another call's RESULT - the deleted-record retry below is a
            // RECOVERY, not a dependency, and rides inside the client's own promise.
            //
            // The client record is resolved in two steps, because this screen serves
            // deleted clients too (it is where they are restored from). The ORM fetch
            // serves non-deleted records only, so a soft-deleted client reads as
            // not-found there; the deleted-record endpoint is asked next - gated on the
            // server for exactly that situation (the same gate as the restore it leads
            // to), which is why that lookup is a named endpoint and not a widened ORM
            // fetch. A client that is neither live nor deleted-and-visible keeps the
            // original not-found error, and any other error propagates untouched. The
            // steps live inline because on_load() may touch nothing but this.args and
            // this.data.
            //
            // The client is FATAL - the page IS this record - so its .catch() RECOVERS
            // or re-throws, it never degrades to a default. The three side lists ARE
            // non-fatal and degrade to empty: a failing tab must not blank the record.
            const client_promise = Client_Model.fetch(this.args.id).catch(async (not_found_error) => {
                if (not_found_error.code !== Ajax.ERROR_NOT_FOUND) throw not_found_error;
                const deleted_result = await Frontend_Clients_Controller.fetch_deleted({id: this.args.id});
                if (!deleted_result.client) throw not_found_error;
                return deleted_result.client;
            });

            const [client, contacts_result, projects_result, activity_result] = await Promise.all([
                client_promise,
                Frontend_Clients_Controller.client_contacts({id: this.args.id}).catch(() => ({contacts: []})),
                Frontend_Clients_Controller.client_projects({id: this.args.id}).catch(() => ({projects: []})),
                Frontend_Clients_Controller.client_activity({id: this.args.id}).catch(() => ({activity: []})),
            ]);
            this.data.client = client;
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
        // Delegated handlers, NAMESPACED AND IDEMPOTENT: this.$ survives every
        // render() while on_ready() re-fires on each one, so one .off('.cva') here
        // clears this page's prior binds before they are re-attached. A one-shot
        // instance flag would be wrong in both directions - flags die with the
        // instance, handlers live on the element.
        this.$.off('.cva');

        // KPI cells that jump to a tab (Kpi_Cell $clickable $tab). This stays on the
        // SHELL because it crosses regions: the cells live in Clients_View_Sidebar and
        // the Tab_Bar they drive lives in the main column.
        this.$.on('click.cva', '.Kpi_Cell--clickable', (e) => {
            const tab = $(e.currentTarget).attr('data-kpi-tab');
            const tab_bar = this.sid('tabs');
            if (tab && tab_bar) tab_bar.activate(tab);
        });

        // The sidebar owns its own record actions and announces a mutation; repainting
        // the page is the shell's call, so the one fetch of the record stays here.
        // A COMPONENT event, not a DOM one: no namespace and no .off() exist for these,
        // and none is needed - a parent render destroys the sidebar, so every on_ready()
        // registers against a brand-new instance that has fired nothing yet. Absent in
        // the loading/error branches, where no sidebar is rendered.
        const sidebar = this.sid('sidebar');
        if (sidebar) sidebar.on('client_changed', () => this.reload());
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
