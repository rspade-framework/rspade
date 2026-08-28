/**
 * Contact add/edit action
 *
 * Handles both creating new contacts and editing existing ones.
 */
@route('/contacts/add')
@route('/contacts/edit/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Contacts_Edit_Action extends Spa_Action {
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
            first_name: '',
            last_name: '',
            title: '',
            email: '',
            email_secondary: '',
            phone_work: '',
            phone_cell: '',
            phone_other: '',
            address: '',
            city: '',
            state: '',
            zip: '',
            client_id: this.args.client_id || '',
            is_active: '1',
            priority: '2',
            notes: '',
        };

        // Priority options
        this.data.priority_options = [
            {value: '1', label: 'Low'},
            {value: '2', label: 'Medium'},
            {value: '3', label: 'High'},
            {value: '4', label: 'Urgent'},
        ];

        this.data.error_data = null;
        this._record_settled = false;
    }

    async on_load() {
        if (!this.data.is_edit) {
            // Add mode - no data to load
            return;
        }

        try {
            // Load contact data via Model.fetch()
            const contact = await Contact_Model.fetch(this.args.id);

            // Build form data from contact record
            this.data.form_data = {
                id: contact.id,
                first_name: contact.first_name || '',
                last_name: contact.last_name || '',
                title: contact.title || '',
                email: contact.email || '',
                email_secondary: contact.email_secondary || '',
                phone_work: contact.phone_work || '',
                phone_cell: contact.phone_cell || '',
                phone_other: contact.phone_other || '',
                address: contact.address || '',
                city: contact.city || '',
                state: contact.state || '',
                zip: contact.zip || '',
                client_id: contact.client_id || '',
                is_active: contact.is_active ? '1' : '0',
                priority: str(contact.priority || '2'),
                notes: contact.notes || '',
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
        // after the children are ready, which is exactly why a loading indicator can
        // never be *set* here, and exactly why this is the right place to clear it.
        //
        // The framework only re-rendered if the loaded data CHANGED, so a cached
        // revisit whose data matched keeps the cached form instance - seed it
        // explicitly. vals() skips fields the user has touched; there are none,
        // because the overlay blocked input.
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
    }

    // Breadcrumb methods
    async page_title() {
        await this.await_loaded();
        if (this.data.is_edit) {
            const f = this.data.form_data;
            return `Edit: ${f.first_name} ${f.last_name}`.trim();
        }
        return 'New Contact';
    }

    async breadcrumb_label_active() {
        return this.data.is_edit ? 'Edit Contact' : 'New Contact';
    }

    async breadcrumb_parent() {
        if (this.data.is_edit) {
            return Rsx.Route('Contacts_View_Action', { id: this.args.id });
        }
        if (this.data.from_client) {
            return Rsx.Route('Clients_View_Action', { id: this.args.client_id });
        }
        return Rsx.Route('Contacts_Index_Action');
    }

    // Action buttons for page header
    page_actions() {
        const back_url = this.data.from_client
            ? Rsx.Route('Clients_View_Action', this.args.client_id)
            : Rsx.Route('Contacts_Index_Action');
        return `
            <div class="d-flex gap-2">
                <a href="${back_url}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        `;
    }
}
