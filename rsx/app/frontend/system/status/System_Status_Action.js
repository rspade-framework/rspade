@route('/frontend/system/status')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('System Status')
@auth('is_logged_in')
class System_Status_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.placeholder = true;
    }

    async breadcrumb_label_active() { return 'System Status'; }
    async breadcrumb_parent() { return Rsx.Route('Dashboard_Index_Action'); }
}
