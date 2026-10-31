/**
 * Settings User Settings Action
 *
 * User preferences, in three forms that each save on their own button: timezone and
 * appearance through the framework's own Rsx_Timezone_Controller and
 * Rsx_Dark_Mode_Controller, and
 * notification and privacy preferences through this feature's
 * Frontend_Settings_User_Settings_Controller.
 */
@route('/frontend/settings/user_settings')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('User Settings')
@auth('is_logged_in')
@spinner_until_ready('always')
class Settings_User_Settings_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.timezone_options = [];
        this.data.timezone_form_data = {
            timezone: '',
        };

        this.data.theme_options = [];
        this.data.theme_form_data = {
            dark_mode: Rsx_Dark_Mode.MODE_AUTO,
        };

        // undefined = not loaded yet; null = the user has no email address on this site.
        this.data.email_address = undefined;
        this.data.profile_visibility_options = [];
        this.data.preferences_form_data = {};
    }

    async on_load() {
        const [options, settings, theme, preferences] = await Promise.all([
            Rsx_Timezone_Controller.timezone_options(),
            Rsx_Timezone_Controller.get_settings(),
            Rsx_Dark_Mode_Controller.get_settings(),
            Frontend_Settings_User_Settings_Controller.get_preferences(),
        ]);

        this.data.email_address = preferences.email_address;
        this.data.profile_visibility_options = preferences.profile_visibility_options;
        this.data.preferences_form_data = preferences.form_data;

        // The preference is ONE value: '' is Automatic (each browser's own zone - this one's
        // is named in the label), anything else the zone the user chose.
        this.data.timezone_options = [
            {value: '', label: 'Automatic - follow my browser (' + Rsx_Timezone_Cookie.browser_value() + ')'},
            ...options,
        ];

        this.data.theme_options = theme.options;
        this.data.theme_form_data = {
            dark_mode: theme.mode,
        };

        this.data.timezone_form_data = {
            timezone: settings.timezone ?? '',
        };
    }

    on_ready() {
        const that = this;
        const form = this.sid('timezone_form');

        // 'submitted' is the form's successful-submit event, and its payload is the
        // server's result.
        form.on('submitted', function (component, result) {
            that._on_timezone_saved(result);
        });

        this.sid('theme_form').on('submitted', function (component, result) {
            that._on_theme_saved(result);
        });

        this.sid('preferences_form').on('submitted', function () {
            Flash_Alert.success('Preferences saved.');
        });
    }

    /**
     * Switch the page on screen to the mode the server just stored. No reload: the theme
     * is a class and attributes on <html>, and Rsx_Dark_Mode.set_mode() rewrites them in
     * place (and, under auto, resumes following the operating system).
     */
    _on_theme_saved(result) {
        Rsx_Dark_Mode.set_mode(result.mode);
        Flash_Alert.success('Theme saved.');
    }

    /**
     * @param {Object} result - {changed, timezone} from Rsx_Timezone_Controller
     */
    _on_timezone_saved(result) {
        if (result && result.changed === true) {
            // Every datetime already on the screen - and in every page the SPA would render
            // next from cached client state - was produced in the OLD zone. We do not reload
            // out from under the user, but the next navigation is a full page load.
            Spa.disable();
            Flash_Alert.success('Timezone updated. The rest of the app will refresh when you navigate away.');

            return;
        }

        Flash_Alert.success('Timezone settings saved.');
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Timezone, theme, notifications and privacy';
    }
}
