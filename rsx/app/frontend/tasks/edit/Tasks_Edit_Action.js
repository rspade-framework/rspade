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
@auth('is_logged_in')
class Tasks_Edit_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.is_edit = !!this.args.id;

        this.data.form_data = {
            title: '',
            description: '',
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
        this._record_settled = false;
    }

    async on_load() {
        if (!this.data.is_edit) return;

        try {
            const task = await Task_Model.fetch(this.args.id);
            this.data.form_data = {
                id: task.id,
                title: task.title || '',
                description: task.description || '',
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

    /**
     * The overlay lives on the FORM, and the form is found by class - the action
     * owns when it is on, not how it looks.
     *
     * @param {boolean} loading
     */
    _set_form_loading(loading) {
        const $form = this.$.find('.Rsx_Form').first();
        if ($form.exists()) {
            $form.component().set_loading(loading);
        }
    }

    on_render() {
        // ARM on every render while THIS instance's record load has not settled -
        // including a cached revisit, whose cached data cannot be trusted to describe
        // an in-flight revalidation. That is also why the flag is an INSTANCE property
        // and not this.data: this.data is cached, and a cached "settled" would lie.
        //
        // The overlay has to be re-armed per render because renders rebuild the DOM.
        this._set_form_loading(this.data.is_edit && !this._record_settled);
    }

    on_ready() {
        // The load is complete BY DEFINITION here - on_ready fires after on_load and
        // after the children are ready, which is why a loading indicator can never be
        // *set* here, and why this is the right place to clear it.
        if (this.data.is_edit) {
            this._record_settled = true;
            if (!this.data.error_data && this.data.form_data) {
                const $form = this.$.find('.Rsx_Form').first();
                if ($form.exists()) {
                    $form.component().vals(this.data.form_data);
                }
            }
            this._set_form_loading(false);
        }

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

        const res = await Frontend_Tasks_Controller.resolve_parent_project({
            taskable_type: p.type,
            taskable_id: p.id,
        });

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
        return `
            <div class="d-flex gap-2">
                <a href="${back_url}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        `;
    }
}
