/**
 * Client add/edit action
 *
 * Handles both creating new clients and editing existing ones.
 */
@route('/clients/add')
@route('/clients/edit/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data', 'can_edit_data')
@spinner_until_ready('always')
class Clients_Edit_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        // Determine if editing or adding based on URL args
        this.data.is_edit = !!this.args.id;

        // Form data stub - provide empty strings for all fields to avoid undefined
        this.data.form_data = {
            name: '',
            website: '',
            email: '',
            phone: '',
            fax: '',
            address_street: '',
            city: '',
            state: '',
            zip: '',
            address_country: 'US',
            industry: '',
            company_size: '',
            established_year: '',
            revenue_range: '',
            facebook_url: '',
            twitter_handle: '',
            linkedin_url: '',
            instagram_handle: '',
            status_id: Client_Model.STATUS_ACTIVE,
            preferred_contact_method: 'email',
            newsletter_opt_in: '0',
            // A declared text-type column: its input edits a value object, so the empty
            // is null and not ''. See Project_Model::$text_types.
            notes: null,
            tags: [],
        };

        // Dropdown options
        this.data.industry_options = [
            'Technology',
            'Finance',
            'Healthcare',
            'Manufacturing',
            'Retail',
            'Education',
            'Real Estate',
            'Legal',
            'Consulting',
            'Other',
        ];

        this.data.company_size_options = [
            {value: '1-10', label: '1-10 employees'},
            {value: '11-50', label: '11-50 employees'},
            {value: '51-200', label: '51-200 employees'},
            {value: '201-500', label: '201-500 employees'},
            {value: '501-1000', label: '501-1000 employees'},
            {value: '1000+', label: '1000+ employees'},
        ];

        this.data.revenue_options = [
            'Under $1M',
            '$1M - $10M',
            '$10M - $50M',
            '$50M - $100M',
            'Over $100M',
        ];

        this.data.status_options = Client_Model.status_id__enum_select();

        this.data.contact_method_options = {
            email: 'Email',
            phone: 'Phone',
            text: 'Text Message',
            any: 'Any Method',
        };

        this.data.error_data = null;
    }

    async on_load() {
        if (!this.data.is_edit) {
            // Add mode - no data to load
            return;
        }

        try {
            const client = await Client_Model.fetch(this.args.id);

            // Build form data from client record
            this.data.form_data = {
                id: client.id,
                name: client.name,
                website: client.website,
                email: client.email,
                phone: client.phone,
                fax: client.fax,
                address_street: client.address_street,
                city: client.city,
                state: client.state,
                zip: client.zip,
                address_country: client.address_country || 'US',
                industry: client.industry,
                company_size: client.company_size,
                established_year: client.established_year,
                revenue_range: client.revenue_range,
                facebook_url: client.facebook_url,
                twitter_handle: client.twitter_handle,
                linkedin_url: client.linkedin_url,
                instagram_handle: client.instagram_handle,
                status_id: client.status_id || Client_Model.STATUS_ACTIVE,
                preferred_contact_method: client.preferred_contact_method || 'email',
                newsletter_opt_in: client.newsletter_opt_in ? '1' : '0',
                notes: client.notes,
                tags: client.tags || [],
            };
        } catch (e) {
            this.data.error_data = e;
        }
    }

    // Breadcrumb methods
    async page_title() {
        await this.await_loaded();
        if (this.data.is_edit) {
            return `Edit: ${this.data.form_data.name}`.trim();
        }
        return 'New Client';
    }

    async breadcrumb_label_active() {
        return this.data.is_edit ? 'Edit Client' : 'New Client';
    }

    async breadcrumb_parent() {
        if (this.data.is_edit) {
            return Rsx.Route('Clients_View_Action', { id: this.args.id });
        }
        return Rsx.Route('Clients_Index_Action');
    }

    // Action buttons for page header
    page_actions() {
        return {
            component: 'Record_Page_Actions',
            args: { back_href: Rsx.Route('Clients_Index_Action') },
        };
    }
}
