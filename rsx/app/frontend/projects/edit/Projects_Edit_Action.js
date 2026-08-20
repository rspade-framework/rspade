/**
 * Project add/edit action
 *
 * Handles both creating new projects and editing existing ones.
 */
@route('/projects/add')
@route('/projects/edit/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Projects_Edit_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        // Determine if editing or adding based on URL args
        this.data.is_edit = !!this.args.id;

        // Check if client_id was passed as query param (from client view page)
        this.data.from_client = !!this.args.client_id;

        // Form data stub - provide empty strings for all fields to avoid undefined
        this.data.form_data = {
            name: '',
            description: '',
            client_id: this.args.client_id || '',
            parent_project_id: '',
            status: '1',
            priority: '2',
            start_date: '',
            due_date: '',
            budget: '',
            notes: '',
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
        this.data.loading = this.data.is_edit; // Only show loading for edit mode
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
                    description: project.description || '',
                    client_id: project.client_id || '',
                    parent_project_id: project.parent_project_id || '',
                    status: str(project.status || '1'),
                    priority: str(project.priority || '2'),
                    start_date: project.start_date || '',
                    due_date: project.due_date || '',
                    budget: project.budget || '',
                    notes: project.notes || '',
                    contacts: [],
                    assigned_users: [],
                };
            }

            // Load the multi-select option lists (users always; contacts scoped to the
            // known client) plus the current pivot selections for prefill on edit.
            const client_id = this.data.form_data.client_id || this.args.client_id || 0;
            const options = await Frontend_Projects_Controller.project_form_options({
                client_id: client_id || 0,
                project_id: this.data.is_edit ? this.args.id : 0,
            });
            this.data.contact_options = options.contacts;
            this.data.user_options = options.users;
            this.data.form_data.contacts = options.selected_contacts;
            this.data.form_data.assigned_users = options.selected_users;
        } catch (e) {
            this.data.error_data = e;
        }

        this.data.loading = false;
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
        return `
            <div class="d-flex gap-2">
                <a href="${back_url}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        `;
    }
}
