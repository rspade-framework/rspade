/**
 * Billable entry add/edit - the detail-tables form.
 *
 * Add mode is per kind: the New entry menu opens /billing/add?type=N (and, from a project's
 * Billing tab, &project_id=N), and the kind is fixed for the page. Edit mode takes the kind
 * from the stored entry - the discriminator never changes - and loads the kind's detail
 * through the accessor. The kind decides the fields: TIME hours + hourly rate, EXPENSE vendor +
 * cost (+ the receipt, once the entry exists), FIXED_FEE the amount. Field lengths come from
 * Billable_Entry_Model.field_length(), which spans the base and detail columns.
 *
 * The Task select offers the chosen project's tasks; choosing another project reloads them
 * (form_options) and clears the task. A NEW time entry's hourly rate is filled from the
 * project's rate when the project is chosen - the entry keeps its own copy afterwards, so an
 * edit never re-prices from the project. The Person select appears only to an approver
 * (form_options.can_choose_user); everybody else logs their own work.
 */
@route('/billing/add')
@route('/billing/edit/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data', 'can_edit_data')
@spinner_until_ready('always')
class Billing_Edit_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.is_edit = !!this.args.id;
        this.data.type_id = this.data.is_edit ? null : int(this.args.type);

        // The money fields edit an Rsx_Money value object: their empty is null, never ''.
        this.data.form_data = {
            type_id: this.data.type_id,
            project_id: this.args.project_id || '',
            task_id: '',
            user_id: window.rsxapp.user.id,
            entry_date: Rsx_Date.today(),
            description: '',
            hours: '',
            hourly_rate: null,
            vendor: '',
            cost: null,
            amount: null,
        };

        this.data.project_options = [];
        this.data.task_options = [];
        this.data.user_options = [];
        this.data.can_choose_user = false;
        this.data.error_data = null;
        this._watched_form = null;
    }

    async on_load() {
        try {
            if (this.data.is_edit) {
                const entry = await Billable_Entry_Model.fetch(this.args.id);
                this.data.type_id = entry.type_id;

                // The fetched record IS the form's seed, with the page's input shapes and
                // the detail fields assigned onto it: the form judges a value a
                // before_submit hook supplies against the record by the column's own rule
                // (Billable_Entry_Model.same_value()), which a plain copy of the fields
                // cannot offer (rsx:man form_conventions, EDITING A RECORD).
                const fd = Object.assign(entry, {
                    task_id: entry.task_id || '',
                    entry_date: entry.entry_date || '',
                    description: entry.description || '',
                    hours: '',
                    hourly_rate: null,
                    vendor: '',
                    cost: null,
                });

                // GENUINE DEPENDENCY: which detail accessor to call is decided by
                // entry.type_id. Resolved from the embedded payload - no network call.
                if (entry.type_id === Billable_Entry_Model.TYPE_TIME) {
                    const d = await entry.billable_entry_time();
                    if (d) {
                        fd.hours = d.hours;
                        fd.hourly_rate = d.hourly_rate;
                    }
                } else if (entry.type_id === Billable_Entry_Model.TYPE_EXPENSE) {
                    const d = await entry.billable_entry_expense();
                    if (d) {
                        fd.vendor = d.vendor || '';
                        fd.cost = d.cost;
                    }
                }

                this.data.form_data = fd;
            }

            // GENUINE DEPENDENCY - stays sequential: on an edit, this call's project_id
            // ARGUMENT is the entry's, fetched above.
            const options = await Frontend_Billing_Controller.form_options({
                project_id: this.data.form_data.project_id || 0,
                entry_id: this.data.is_edit ? this.args.id : 0,
            });
            Object.assign(this.data, Billing_Edit_Action.option_lists(options));

            // A new time entry starts at its project's rate.
            if (!this.data.is_edit && this.data.type_id === Billable_Entry_Model.TYPE_TIME) {
                this.data.form_data.hourly_rate = options.default_hourly_rate;
            }
        } catch (e) {
            this.data.error_data = e;
        }
    }

    /**
     * The form's choices from a form_options() answer, as select options. Static: on_load()
     * may touch only this.args and this.data.
     *
     * @param {Object} options - Frontend_Billing_Controller.form_options()'s answer
     * @returns {{project_options: Array, task_options: Array, user_options: Array, can_choose_user: boolean}}
     */
    static option_lists(options) {
        return {
            project_options: options.projects.map((p) => ({
                value: p.id,
                label: p.client_name ? `${p.label} (${p.client_name})` : p.label,
            })),
            task_options: options.tasks.map((t) => ({ value: t.id, label: t.label })),
            user_options: options.users.map((u) => ({ value: u.id, label: u.label })),
            can_choose_user: options.can_choose_user,
        };
    }

    on_ready() {
        this._watch_form_for_changes();

        if (this.data.error_data) return;

        // The user picked another project: offer its tasks, and price a new time entry at its rate.
        const project = this.sid('project_select');
        if (project) {
            project.on('input', (component, value) => this._project_changed(value));
        }

        // A receipt attached from the edit page is a change to the entry.
        const receipt = this.sid('receipt');
        if (receipt) {
            receipt.on('changed', () => Flash_Alert.success('Receipt attached'));
        }
    }

    async _project_changed(project_id) {
        const options = await this.alive(Frontend_Billing_Controller.form_options({
            project_id: project_id || 0,
            entry_id: this.data.is_edit ? this.args.id : 0,
        }));

        this.sid('task_select').set_options(Billing_Edit_Action.option_lists(options).task_options);

        const rate = this.sid('hourly_rate');
        if (!this.data.is_edit && rate && options.default_hourly_rate !== null) {
            rate.val(options.default_hourly_rate);
        }
    }

    /**
     * The navigation guard follows the form's 'dirty' event (the fully commented example is
     * Contacts_Edit_Action), and goes off on the Cancel link and in on_stop(). A re-render
     * replaces the form component, so this wires the instance it is given and only that one.
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

    kind_label() {
        if (this.data.type_id === Billable_Entry_Model.TYPE_TIME) return 'Time Entry';
        if (this.data.type_id === Billable_Entry_Model.TYPE_EXPENSE) return 'Expense';
        if (this.data.type_id === Billable_Entry_Model.TYPE_FIXED_FEE) return 'Fixed Fee';
        return 'Entry';
    }

    async page_title() {
        await this.await_loaded();
        return this.data.is_edit ? `Edit ${this.kind_label()}` : `New ${this.kind_label()}`;
    }

    async breadcrumb_label_active() {
        await this.await_loaded();
        return this.data.is_edit ? `Edit ${this.kind_label()}` : `New ${this.kind_label()}`;
    }

    async breadcrumb_parent() {
        if (this.data.is_edit) {
            return Rsx.Route('Billing_View_Action', this.args.id);
        }
        return Rsx.Route('Billing_Index_Action');
    }

    page_actions() {
        return {
            component: 'Record_Page_Actions',
            args: {
                back_href: this.data.is_edit
                    ? Rsx.Route('Billing_View_Action', this.args.id)
                    : Rsx.Route('Billing_Index_Action'),
            },
        };
    }
}
