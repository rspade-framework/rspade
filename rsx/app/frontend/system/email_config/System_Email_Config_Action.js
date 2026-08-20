@route('/frontend/system/email_config')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Email Configuration')
@auth('is_logged_in')
class System_Email_Config_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.config = null;
        this.data.loading = true;
    }

    async on_load() {
        this.data.config = await System_Email_Controller.get_config();
        this.data.loading = false;
    }

    async breadcrumb_label_active() { return 'Email Configuration'; }
    async breadcrumb_parent() { return Rsx.Route('System_Status_Action'); }
}
