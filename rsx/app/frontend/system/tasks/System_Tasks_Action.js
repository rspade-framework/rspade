@route('/frontend/system/tasks')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Scheduled Tasks')
@auth('is_logged_in')
class System_Tasks_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.placeholder = true;
    }

    async breadcrumb_label_active() { return 'Scheduled Tasks'; }
    async breadcrumb_parent() { return Rsx.Route('System_Status_Action'); }
}
