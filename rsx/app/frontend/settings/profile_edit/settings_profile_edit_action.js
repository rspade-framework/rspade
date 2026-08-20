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
        this.data.loading = true;
    }

    async on_load() {
        try {
            this.data.form_data = await Frontend_Settings_Profile_Edit_Controller.get_profile_for_edit();
        } catch (e) {
            this.data.error_data = e;
        }

        this.data.loading = false;
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Update your profile information';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Settings_Profile_Display_Action');
    }
}
