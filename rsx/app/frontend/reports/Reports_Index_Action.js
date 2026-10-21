/**
 * Reports Index Action
 *
 * Reports overview: a live, site-scoped summary strip of real counts.
 */
@route('/reports')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Reports')
@auth('is_logged_in', 'can_view_data')
class Reports_Index_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.stats = { active_clients: 0, active_projects: 0, total_contacts: 0 };
        this.data.loading = true;
    }

    async on_load() {
        this.data.stats = await Frontend_Reports_Controller.report_stats();
        this.data.loading = false;
    }

    // Breadcrumb methods
    async breadcrumb_label_active() {
        return 'Analytics and business insights';
    }
}
