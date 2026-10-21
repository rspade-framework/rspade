/**
 * Settings Site Settings Action
 *
 * The current site's name, loaded from and saved to Site_Model through
 * Frontend_Settings_Site_Settings_Controller.
 */
@route('/frontend/settings/site_settings')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Site Settings')
@auth('is_logged_in', 'can_manage_site_settings')
class Settings_Site_Settings_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        // Instance property, not this.data: this.data is cached, and a cached "settled"
        // would lie on a revisit while revalidation was still in flight.
        this._settings_settled = false;

        this.data.name_max_length = -1;
        this.data.form_data = {
            name: '',
        };
    }

    async on_load() {
        const settings = await Frontend_Settings_Site_Settings_Controller.get_settings();

        this.data.name_max_length = settings.name_max_length;
        this.data.form_data = settings.form_data;
    }

    on_render() {
        // The REAL form renders at once behind its loading overlay until the load settles.
        this.sid('site_form').set_loading(!this._settings_settled);
    }

    on_ready() {
        const that = this;
        const form = this.sid('site_form');

        // A cache-match revisit keeps the cached form instance: seed it explicitly.
        this._settings_settled = true;
        form.vals(this.data.form_data);
        form.set_loading(false);

        form.on('submitted', function () {
            Flash_Alert.success('Site settings saved.');
            that.reload();
        });
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Configure the site name';
    }
}
