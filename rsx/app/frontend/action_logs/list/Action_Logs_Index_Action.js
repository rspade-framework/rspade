/**
 * Action Logs list page action
 *
 * Displays the action log DataGrid with recent user activities.
 */
@route('/action-logs')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Action Log')
@auth('is_logged_in')
class Action_Logs_Index_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    // Breadcrumb methods
    async breadcrumb_label() {
        return 'Action Log';
    }

    async breadcrumb_label_active() {
        return 'View recent activity history';
    }

    // No action buttons - action logs are read-only
    page_actions() {
        return '';
    }
}
