/**
 * Project add/edit action
 *
 * Handles both creating new projects and editing existing ones.
 */
@route('/projects/add')
@route('/projects/edit/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data', 'can_edit_data')
@spinner_until_ready('always')
class Projects_Edit_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        // Determine if editing or adding based on URL args
        this.data.is_edit = !!this.args.id;

        // client_id arrives as a query param from a client's or a contact's view page;
        // contact_id, from a contact's, names the contact to preselect.
        this.data.from_client = !!this.args.client_id;

        // Form data stub - provide empty strings for all fields to avoid undefined.
        // EXCEPT the declared text-type columns (description, notes): their inputs edit a
        // value object, and '' is a string. null is the right empty for them - it is what
        // the column holds when it has no content, and every input handles it.
        this.data.form_data = {
            name: '',
            description: null,
            client_id: this.args.client_id || '',
            parent_project_id: '',
            status: '1',
            priority: '2',
            start_date: '',
            due_date: '',
            budget: '',
            notes: null,
            contacts: [],
            assigned_users: [],
        };

        // Multi-select option lists (loaded in on_load). Contacts are scoped to the
        // project's client, so they are only offered when a client is known at load
        // (edit mode, or add-from-client). In plain add mode the client is chosen via a
        // dropdown and contacts are assigned after the project is created.
        this.data.contact_options = [];
        this.data.user_options = [];
        this.data.show_contacts = this.data.is_edit || this.data.from_client;

        // Status options
        this.data.status_options = [
            {value: '1', label: 'Active'},
            {value: '2', label: 'On Hold'},
            {value: '3', label: 'Completed'},
            {value: '4', label: 'Cancelled'},
        ];

        // Priority options
        this.data.priority_options = [
            {value: '1', label: 'Low'},
            {value: '2', label: 'Medium'},
            {value: '3', label: 'High'},
            {value: '4', label: 'Urgent'},
        ];

        this.data.error_data = null;
        this._watched_form = null;
    }

    async on_load() {
        try {
            if (this.data.is_edit) {
                // Load project data via Model.fetch()
                const project = await Project_Model.fetch(this.args.id);

                // Build form data from project record
                this.data.form_data = {
                    id: project.id,
                    name: project.name || '',
                    // No `|| ''` on the text-type columns: the value is an object, and
                    // coercing an absent one to a string would hand the editor something
                    // it refuses. null passes through as null.
                    description: project.description,
                    client_id: project.client_id || '',
                    parent_project_id: project.parent_project_id || '',
                    status: str(project.status || '1'),
                    priority: str(project.priority || '2'),
                    start_date: project.start_date || '',
                    due_date: project.due_date || '',
                    budget: project.budget || '',
                    notes: project.notes,
                    contacts: [],
                    assigned_users: [],
                };
            }

            // GENUINE DEPENDENCY - stays sequential: this call's client_id ARGUMENT
            // comes from the project fetched above, so it cannot join a Promise.all()
            // with it. Loads the multi-select option lists (users always; contacts
            // scoped to the known client) plus the current pivot selections.
            const client_id = this.data.form_data.client_id || this.args.client_id || 0;
            const options = await Frontend_Projects_Controller.project_form_options({
                client_id: client_id || 0,
                project_id: this.data.is_edit ? this.args.id : 0,
            });
            this.data.contact_options = options.contacts;
            this.data.user_options = options.users;
            this.data.form_data.contacts = options.selected_contacts;
            this.data.form_data.assigned_users = options.selected_users;

            // Add-from-contact: preselect the contact the user came from, when it is one
            // of the client's contacts offered above.
            if (!this.data.is_edit && this.args.contact_id) {
                const contact_id = int(this.args.contact_id);
                if (options.contacts.some((contact) => contact.id === contact_id)) {
                    this.data.form_data.contacts = [contact_id];
                }
            }
        } catch (e) {
            this.data.error_data = e;
        }
    }

    on_ready() {
        this._watch_form_for_changes();
    }

    /**
     * The navigation guard, as the project form uses it (the fully commented example is
     * Contacts_Edit_Action): it follows the form's 'dirty' event - on when the user has
     * changed a value (never the load of the record or of the option lists), off when
     * every value is back or the save succeeds - and goes off on the Cancel link and in
     * on_stop(). While it is on, the browser asks before the user leaves.
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
            return `Edit: ${this.data.form_data.name}`.trim();
        }
        return 'New Project';
    }

    async breadcrumb_label_active() {
        return this.data.is_edit ? 'Edit Project' : 'New Project';
    }

    async breadcrumb_parent() {
        if (this.data.is_edit) {
            return Rsx.Route('Projects_View_Action', { id: this.args.id });
        }
        if (this.data.from_client) {
            return Rsx.Route('Clients_View_Action', { id: this.args.client_id });
        }
        return Rsx.Route('Projects_Index_Action');
    }

    // Action buttons for page header
    page_actions() {
        const back_url = this.data.from_client
            ? Rsx.Route('Clients_View_Action', this.args.client_id)
            : Rsx.Route('Projects_Index_Action');
        return {
            component: 'Record_Page_Actions',
            args: { back_href: back_url },
        };
    }
}
