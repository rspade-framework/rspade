/**
 * Client add/edit action
 *
 * Handles both creating new clients and editing existing ones.
 */
@route('/clients/add')
@route('/clients/edit/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
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
            notes: '',
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

        this.data.tags = [''];
        this.data.error_data = null;
        this.data.loading = this.data.is_edit; // Only show loading for edit mode
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
            };

            // Tags array
            this.data.tags = client.tags && client.tags.length > 0 ? client.tags : [''];
        } catch (e) {
            this.data.error_data = e;
        }

        this.data.loading = false;
    }

    on_ready() {
        // Handle dynamic tag addition
        this.$sid('add-tag').click(() => {
            this.add_tag();
        });

        // Handle tag removal (delegated)
        this.$.on('click', '.remove-tag', (e) => {
            const $button = $(e.currentTarget);
            const $container = this.$sid('tags-container');
            if ($container.find('.input-group').length > 1) {
                $button.closest('.input-group').remove();
            } else {
                $button.closest('.input-group').find('input').val('');
            }
        });
    }

    add_tag() {
        const tag_html = `
            <div class="input-group mb-2">
                <input type="text" class="form-control" name="tags[]" placeholder="e.g., VIP, Enterprise, Strategic Partner">
                <button class="btn btn-danger remove-tag" type="button">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        this.$sid('tags-container').append(tag_html);
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
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Clients_Index_Action')}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        `;
    }
}
