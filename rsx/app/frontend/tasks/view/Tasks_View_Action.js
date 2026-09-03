/**
 * Task detail view action
 *
 * The five-entity view archetype (matches Clients_View / Contacts_View / Party_View):
 * Page_Scaffold / Card_Widget / Entity_Header / Tab_Bar / Tab_Panels / Section /
 * View_Fields / Record_Table / Feed_Row / Detail_Sidebar / Action_Menu. Tabs:
 * Overview / Subtasks / Activity, with a KPI sidebar. The parent-chain idiom
 * ("Part of: <parent>") renders the polymorphic parent via Entity_Link.
 */
@route('/tasks/view/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Tasks_View_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.task = { title: '' };
        this.data.subtasks = [];
        this.data.activity = [];
        this.data.error_data = null;
        this.data.loading = true;

        // Canonical realtime pattern: subscribe in on_create() so the FIRST on_load() is
        // GATED on subscription establishment -> exactly ONE race-free fetch, strictly
        // after the subscription is live (the initial resync is swallowed; the gated load
        // IS the revalidation). refresh() (not reload()) refetches via on_load() but
        // re-renders ONLY when the data actually changed. Idempotent per component, auto-
        // stops on destroy. A subtask touches its parent task (Task::realtime_touch ->
        // taskable Task), so the Subtasks tab live-refreshes when a child task changes.
        this.subscribe(Task_Model, this.args.id, () => this.refresh());
    }

    async on_load() {
        try {
            // One parallel batch: every call keys off this.args.id, so none of them
            // depends on another's RESULT. The task is FATAL - the page IS this record -
            // and carries no .catch(); the two side lists are non-fatal and degrade to
            // empty so a failing tab cannot blank the record.
            const [task, subtasks_result, activity_result] = await Promise.all([
                Task_Model.fetch(this.args.id),
                Frontend_Tasks_Controller.task_subtasks({ id: this.args.id }).catch(() => ({ subtasks: [] })),
                Frontend_Tasks_Controller.task_activity({ id: this.args.id }).catch(() => ({ activity: [] })),
            ]);
            this.data.task = task;
            this.data.subtasks = subtasks_result.subtasks;
            // Decorate each activity entry with its Feed_Row icon/variant (shared map).
            this.data.activity = activity_result.activity.map(a => ({ ...a, ...Activity_Feed.decorate(a.type_id) }));
        } catch (e) {
            this.data.error_data = e;
        } finally {
            this.data.loading = false;
        }
    }

    // Breadcrumb methods
    async page_title() {
        await this.await_loaded();
        return this.data.task.title || 'Task';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.task.title || 'Task';
    }

    async breadcrumb_label_active() {
        return 'View Task';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Tasks_Index_Action');
    }

    on_ready() {
        // Delegated handlers, NAMESPACED AND IDEMPOTENT: this.$ survives every
        // render() while on_ready() re-fires on each one, so one .off('.tva') here
        // clears this component's prior binds before they are re-attached. A one-shot
        // instance flag would be wrong in both directions - flags die with the
        // instance, handlers live on the element.
        this.$.off('.tva');

        // KPI cells that jump to a tab (Kpi_Cell $clickable $tab).
        this.$.on('click.tva', '.Kpi_Cell--clickable', (e) => {
            const tab = $(e.currentTarget).attr('data-kpi-tab');
            const tab_bar = this.sid('tabs');
            if (tab && tab_bar) tab_bar.activate(tab);
        });

        // Destructive action lives inside the overflow Action_Menu, never as a peer button.
        if (this.$sid('delete-task').exists()) {
            this.$sid('delete-task').click(async () => {
                const confirmed = await Modal.confirm(
                    'Delete Task',
                    'Are you sure you want to delete this task?\n\nThis cannot be undone.',
                    'Delete',
                    'Cancel'
                );
                if (!confirmed) return;
                await Frontend_Tasks_Controller.delete({ id: this.args.id });
                Spa.dispatch(Rsx.Route('Tasks_Index_Action'));
            });
        }
    }

    // Action buttons for page header
    page_actions() {
        const id = this.args.id;
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Tasks_Index_Action')}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                ${id ? `
                <a href="${Rsx.Route('Tasks_Edit_Action', id)}" class="btn btn-primary btn-sm">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                ` : ''}
            </div>
        `;
    }
}
