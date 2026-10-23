/**
 * Task add/edit action
 *
 * Dual-route (/tasks/add + /tasks/edit/:id) form matching Projects_Edit. The Project
 * field is DERIVED-aware: when the selected parent chain reaches a project the field
 * renders read-only (disabled + "Derived from parent chain" note); otherwise it is an
 * editable project picker. The derived state is resolved live from the parent picker
 * via Frontend_Tasks_Controller.resolve_parent_project.
 */
@route('/tasks/add')
@route('/tasks/edit/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data', 'can_edit_data')
@spinner_until_ready('always')
class Tasks_Edit_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.is_edit = !!this.args.id;

        this.data.form_data = {
            title: '',
            // `description` is a declared text type: its input edits a value object, and
            // '' is a string the editor refuses. null is the right empty.
            description: null,
            status: Task_Model.STATUS_PENDING,
            priority: Task_Model.PRIORITY_MEDIUM,
            due_date: '',
            hour_estimate: '',
            assigned_to_user_id: '',
            project_id: '',
            // Composite parent value for Parent_Selector_Input.
            taskable: { type: null, id: null },
        };

        this.data.error_data = null;
        this._watched_form = null;
    }

    async on_load() {
        if (!this.data.is_edit) return;

        try {
            const task = await Task_Model.fetch(this.args.id);
            this.data.form_data = {
                id: task.id,
                title: task.title || '',
                // No `|| ''` on a text-type column: the value is an object, and coercing
                // an absent one to a string would hand the editor something it refuses.
                description: task.description,
                status: task.status || Task_Model.STATUS_PENDING,
                priority: task.priority || Task_Model.PRIORITY_MEDIUM,
                due_date: task.due_date || '',
                hour_estimate: (task.hour_estimate === null || task.hour_estimate === undefined) ? '' : task.hour_estimate,
                assigned_to_user_id: task.assigned_to_user_id || '',
                project_id: task.project_id || '',
                taskable: { type: task.taskable_type || null, id: task.taskable_id || null },
            };
        } catch (e) {
            this.data.error_data = e;
        }
    }

    on_ready() {
        this._watch_form_for_changes();

        if (this.data.error_data) return;

        const picker_el = this.$sid('parent_picker');
        if (!picker_el.exists()) return;
        const picker = picker_el.component();

        // React to parent changes: re-resolve the derived project field.
        picker.on('input', (comp, val) => this._refresh_project(val));

        // Establish the initial derived/editable project state from the loaded parent.
        this._refresh_project(this.data.form_data.taskable);
    }

    /**
     * Resolve whether the project field should be derived (read-only) for the given
     * parent {type,id} and update the project picker accordingly.
     */
    async _refresh_project(parent) {
        const p = parent || {};
        if (!p.type || !p.id) {
            this._set_project_derived(false, null, null);
            return;
        }

        const res = await this.alive(Frontend_Tasks_Controller.resolve_parent_project({
            taskable_type: p.type,
            taskable_id: p.id,
        }));

        if (res.derived) {
            this._set_project_derived(true, res.project_id, res.project_name);
        } else {
            this._set_project_derived(false, null, null);
        }
    }

    _set_project_derived(derived, project_id, project_name) {
        const select = this.$sid('project_select').component();
        const $note = this.$sid('project_note');

        if (derived) {
            select.val(project_id);
            select.set_disabled(true);
            $note.text('Derived from parent chain: ' + (project_name || '')).removeClass('d-none');
        } else {
            select.set_disabled(false);
            $note.addClass('d-none').text('');
        }
    }

    /**
     * The navigation guard, as the task form uses it (the fully commented example is
     * Contacts_Edit_Action): it follows the form's 'dirty' event - on when the user has
     * changed a value (the derived-project fill is programmatic and never counts), off
     * when every value is back or the save succeeds - and goes off on the Cancel link
     * and in on_stop(). While it is on, the browser asks before the user leaves.
     *
     * A re-render replaces the form component, so this wires the instance it is given
     * and only that one.
     */
    _watch_form_for_changes() {
        const $form = this.$.find('.Rsx_Form').first();
        if (!$form.exists()) {
            return;
        }
        const form = $form.component();
        if (form === this._watched_form) {
            return;
        }
        this._watched_form = form;

        form.on('dirty', (form, dirty) => (dirty ? Rsx.set_navigation_guard() : Rsx.clear_navigation_guard()));
    }

    /** The Cancel link: the changes are being thrown away, so nothing is left to ask about. */
    discard_changes() {
        Rsx.clear_navigation_guard();
    }

    on_stop() {
        Rsx.clear_navigation_guard();
    }

    // Breadcrumb methods
    async page_title() {
        await this.await_loaded();
        if (this.data.is_edit) {
            return `Edit: ${this.data.form_data.title}`.trim();
        }
        return 'New Task';
    }

    async breadcrumb_label_active() {
        return this.data.is_edit ? 'Edit Task' : 'New Task';
    }

    async breadcrumb_parent() {
        if (this.data.is_edit) {
            return Rsx.Route('Tasks_View_Action', { id: this.args.id });
        }
        return Rsx.Route('Tasks_Index_Action');
    }

    // Action buttons for page header
    page_actions() {
        const back_url = this.data.is_edit
            ? Rsx.Route('Tasks_View_Action', this.args.id)
            : Rsx.Route('Tasks_Index_Action');
        return {
            component: 'Record_Page_Actions',
            args: { back_href: back_url },
        };
    }
}
