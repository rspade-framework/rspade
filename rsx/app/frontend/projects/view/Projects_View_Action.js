/**
 * Project detail view action
 *
 * Displays a single project, composed from the semantic view-page vocabulary
 * (Page_Scaffold / Card_Widget / Entity_Header / Tab_Bar / Tab_Panels / Section /
 * View_Fields / Record_Table / Feed_Row / Detail_Sidebar). Tabbed like the
 * Clients_View archetype: Overview / Tasks / Activity, with a KPI sidebar.
 */
@route('/projects/view/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Projects_View_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        // Initialize project data to prevent undefined errors during first render
        this.data.project = {
            name: '',
        };
        this.data.tasks = [];
        this.data.subprojects = [];
        this.data.contacts = [];
        this.data.assigned_users = [];
        this.data.activity = [];
        this.data.error_data = null;
        this.data.loading = true;

        // Canonical realtime pattern: subscribe in on_create() so the FIRST on_load() is
        // GATED on subscription establishment -> exactly ONE race-free fetch, strictly
        // after the subscription is live (the initial resync is swallowed; the gated load
        // IS the revalidation). refresh() (not reload()) refetches via on_load() but
        // re-renders ONLY when the data actually changed. Idempotent per component, auto-
        // stops on destroy. A task under this project touches it (Task::realtime_touch ->
        // taskable Project), so the Tasks tab live-refreshes when a child task changes.
        this.subscribe(Project_Model, this.args.id, () => this.refresh());
    }

    async on_load() {
        try {
            // One parallel batch: every call keys off this.args.id, so none of them
            // depends on another's RESULT. The project is FATAL - the page IS this
            // record - and carries no .catch(); the four side lists are non-fatal and
            // degrade to empty so a failing tab cannot blank the record.
            const [project, tasks_result, subprojects_result, people_result, activity_result] = await Promise.all([
                Project_Model.fetch(this.args.id),
                Frontend_Projects_Controller.project_tasks({id: this.args.id}).catch(() => ({tasks: []})),
                Frontend_Projects_Controller.project_subprojects({id: this.args.id}).catch(() => ({subprojects: []})),
                Frontend_Projects_Controller.project_people({id: this.args.id}).catch(() => ({contacts: [], users: []})),
                Frontend_Projects_Controller.project_activity({id: this.args.id}).catch(() => ({activity: []})),
            ]);
            this.data.project = project;
            this.data.tasks = tasks_result.tasks;
            this.data.subprojects = subprojects_result.subprojects;
            this.data.contacts = people_result.contacts;
            this.data.assigned_users = people_result.users;
            // Decorate each activity entry with its Feed_Row icon/variant (shared map).
            this.data.activity = activity_result.activity.map(a => ({...a, ...Activity_Feed.decorate(a.type_id)}));
        } catch (e) {
            // Store error for Universal_Error_Page_Component to handle
            this.data.error_data = e;
        } finally {
            this.data.loading = false;
        }
    }

    // Breadcrumb methods
    async page_title() {
        await this.await_loaded();
        return this.data.project.name || 'Project';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.project.name || 'Project';
    }

    async breadcrumb_label_active() {
        return 'View Project';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Projects_Index_Action');
    }

    on_ready() {
        // Delegated handlers, NAMESPACED AND IDEMPOTENT: this.$ survives every
        // render() while on_ready() re-fires on each one, so one .off('.pva') here
        // clears this component's prior binds before they are re-attached. A one-shot
        // instance flag would be wrong in both directions - flags die with the
        // instance, handlers live on the element.
        this.$.off('.pva');

        // KPI cells that jump to a tab (Kpi_Cell $clickable $tab).
        this.$.on('click.pva', '.Kpi_Cell--clickable', (e) => {
            const tab = $(e.currentTarget).attr('data-kpi-tab');
            const tab_bar = this.sid('tabs');
            if (tab && tab_bar) tab_bar.activate(tab);
        });

        // People_List rows: a clicked contact navigates to its view (the contacts list is
        // $clickable; the assigned-users list is display-only, so it never fires). The list
        // hands back the exact person object, so we route on its id/type.
        this.$.find('.People_List').each((i, el) => {
            $(el).component().on('person_click', (c, d) => {
                if (d.person && d.person.type === 'contact' && d.person.id) {
                    Spa.dispatch(Rsx.Route('Contacts_View_Action', d.person.id));
                }
            });
        });
    }

    // Action buttons for page header
    page_actions() {
        const id = this.args.id;
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Projects_Index_Action')}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                ${id ? `
                <a href="${Rsx.Route('Projects_Edit_Action', id)}" class="btn btn-primary btn-sm">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                ` : ''}
            </div>
        `;
    }
}
