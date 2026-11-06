/**
 * Settings General Action
 *
 * Redirect action - immediately redirects to the profile display page (the entry is
 * replaced, so Back skips this one).
 */
@route('/frontend/settings')
@route('/frontend/settings/general')
@layout('Frontend_Spa_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Settings_General_Action extends Spa_Action {
    on_ready() {
        Rsx.redirect(Rsx.Route('Settings_Profile_Display_Action'));
    }
}
