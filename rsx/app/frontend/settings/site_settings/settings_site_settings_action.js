/**
 * Settings Site Settings Action
 *
 * Site configuration (logo, name, description).
 * No three-state loading - site data available from window.rsxapp.site.
 */
@route('/frontend/settings/site_settings')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Site Settings')
@auth('is_logged_in')
class Settings_Site_Settings_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        // Site data is available synchronously from rsxapp
        const site = window.rsxapp.site || {};

        this.data.form_data = {
            site_logo: '',  // Will need to load via Ajax if we want current logo key
            name: site.name || '',
            description: site.description || '',
        };
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Configure site name, logo, and description';
    }
}
