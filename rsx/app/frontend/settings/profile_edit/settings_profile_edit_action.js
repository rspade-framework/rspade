/**
 * Settings Profile Edit Action
 *
 * Edit form for the current user's profile information.
 */
@route('/frontend/settings/profile_edit')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Edit Profile')
@auth('is_logged_in')
class Settings_Profile_Edit_Action extends Spa_Action {
    // Composes with Page_Scaffold inside the Settings_Layout content pane (both
    // layers reconcile; Settings_Layout stamps settings-content--scaffolded).
    scaffolded = true;

    on_create() {
        // Form data stub
        this.data.form_data = {
            profile_photo: '',
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
            title: '',
            department: '',
            bio: '',
        };

        this.data.error_data = null;

        // This page is ALWAYS an edit form, so it is always loading a record.
        this._record_settled = false;
    }

    async on_load() {
        try {
            this.data.form_data = await Frontend_Settings_Profile_Edit_Controller.get_profile_for_edit();
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
        // including a cached revisit, whose cached data cannot describe an in-flight
        // revalidation. That is also why the flag is an INSTANCE property and not
        // this.data: this.data is cached, and a cached "settled" would lie.
        this._set_form_loading(!this._record_settled);
    }

    on_ready() {
        // Ready means THIS action's load is complete, by definition - the only correct
        // moment to drop a loader, and the reason one can never be raised here.
        this._record_settled = true;

        if (!this.data.error_data && this.data.form_data) {
            const $form = this.$.find('.Rsx_Form').first();
            if ($form.exists()) {
                $form.component().vals(this.data.form_data);
            }
        }

        this._set_form_loading(false);
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Update your profile information';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Settings_Profile_Display_Action');
    }
}
