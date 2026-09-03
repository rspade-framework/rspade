/**
 * Party add/edit. Add mode is per-type (?type=N from the New menu); the type is fixed for
 * the page. Edit mode loads the base + its type-specific detail via the CTI accessor. The
 * type-specific fields use Party_Model.field_length(), which spans base + detail columns.
 */
@route('/party/add')
@route('/party/edit/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Party_Edit_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.is_edit = !!this.args.id;
        this.data.type_id = this.data.is_edit ? null : int(this.args.type);

        this.data.form_data = {
            type_id: this.data.type_id,
            name: '', email: '', phone: '', notes: '',
            first_name: '', last_name: '', title: '', date_of_birth: '',
            legal_name: '', tax_identifier: '', industry: '', employee_count: '',
        };

        this.data.error_data = null;
        this._record_settled = false;
    }

    async on_load() {
        if (!this.data.is_edit) {
            return;
        }

        try {
            const party = await Party_Model.fetch(this.args.id);
            this.data.type_id = party.type_id;

            const fd = {
                id: party.id,
                type_id: party.type_id,
                name: party.name || '',
                email: party.email || '',
                phone: party.phone || '',
                notes: party.notes || '',
                first_name: '', last_name: '', title: '', date_of_birth: '',
                legal_name: '', tax_identifier: '', industry: '', employee_count: '',
            };

            // GENUINE DEPENDENCY - stays sequential: WHICH detail accessor to call is
            // decided by party.type_id, which only exists once the party has arrived.
            if (party.type_id === Party_Model.TYPE_PERSON) {
                const d = await party.party_person();
                if (d) {
                    fd.first_name = d.first_name || '';
                    fd.last_name = d.last_name || '';
                    fd.title = d.title || '';
                    fd.date_of_birth = d.date_of_birth || '';
                }
            } else if (party.type_id === Party_Model.TYPE_COMPANY) {
                const d = await party.party_company();
                if (d) {
                    fd.legal_name = d.legal_name || '';
                    fd.tax_identifier = d.tax_identifier || '';
                    fd.industry = d.industry || '';
                    fd.employee_count = d.employee_count || '';
                }
            }

            this.data.form_data = fd;
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

    _type_label() {
        if (this.data.type_id === Party_Model.TYPE_PERSON) return 'Person';
        if (this.data.type_id === Party_Model.TYPE_COMPANY) return 'Company';
        return 'Group';
    }

    async page_title() {
        return this.data.is_edit ? 'Edit Party' : `New ${this._type_label()}`;
    }

    async breadcrumb_label_active() {
        return this.data.is_edit ? 'Edit Party' : `New ${this._type_label()}`;
    }

    async breadcrumb_parent() {
        return Rsx.Route('Party_Index_Action');
    }

    page_actions() {
        return `
            <a href="${Rsx.Route('Party_Index_Action')}" class="btn btn-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        `;
    }
}
