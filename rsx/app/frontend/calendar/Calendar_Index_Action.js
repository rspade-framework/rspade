/**
 * Calendar Index Action
 *
 * Calendar view placeholder (Page_Scaffold + Placeholder_Card). No calendar
 * backend exists yet, so the dead New Event / Month-Week-Day header controls
 * were removed - an honest coming-soon panel (D8).
 */
@route('/calendar')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Calendar')
@auth('is_logged_in')
class Calendar_Index_Action extends Spa_Action {
    scaffolded = true;

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'View your schedule and events';
    }
}
