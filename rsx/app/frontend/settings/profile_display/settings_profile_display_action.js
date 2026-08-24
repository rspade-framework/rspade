/**
 * Settings Profile Display Action
 *
 * Displays the current user's profile information (view-only).
 */
@route('/frontend/settings/profile_display')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Profile')
@auth('is_logged_in')
class Settings_Profile_Display_Action extends Spa_Action {
    // Composes with Page_Scaffold inside the Settings_Layout content pane. Both
    // layers reconcile: Frontend_Spa_Layout yields the outer page-content width/
    // padding, and Settings_Layout stamps settings-content--scaffolded so the
    // scaffold owns its padding within the settings pane (Batch G).
    scaffolded = true;

    on_create() {
        // User profile stub
        this.data.user = {
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
            role_id__label: 'Member',
            created_at: null,
            last_login_at: null,
            profile_photo_attachment_id: null,
            user_profile: null,
        };

        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            this.data.user = await Frontend_Settings_Profile_Display_Controller.get_profile();
        } catch (e) {
            this.data.error_data = e;
        }

        this.data.loading = false;
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'View your profile information';
    }
}
