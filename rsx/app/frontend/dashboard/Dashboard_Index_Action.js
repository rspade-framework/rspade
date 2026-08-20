/**
 * Dashboard main page action
 *
 * The business overview: four headline KPIs, a recent-activity feed, and the
 * active-projects / recent-contacts / open-tasks widgets. Every figure is a
 * real, site-scoped query served by Frontend_Dashboard_Controller.dashboard_data
 * (no invented numbers). Composed from the semantic view-page vocabulary
 * (Page_Scaffold / Stat_Group / Widget_Grid / Section / Record_Table / Feed_Row).
 */
@route('/dashboard')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Dashboard')
@auth('is_logged_in')
class Dashboard_Index_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        // Defaults so the first (pre-load) render is safe.
        this.data.loading = true;
        this.data.stats = { active_projects: 0, total_contacts: 0, open_tasks: 0, overdue_tasks: 0 };
        this.data.recent_activity = [];
        this.data.active_projects = [];
        this.data.recent_contacts = [];
        this.data.todays_tasks = [];
    }

    async on_load() {
        const data = await Frontend_Dashboard_Controller.dashboard_data();
        this.data.stats = data.stats;
        this.data.recent_activity = data.recent_activity;
        this.data.active_projects = data.active_projects;
        this.data.recent_contacts = data.recent_contacts;
        this.data.todays_tasks = data.todays_tasks;
        this.data.loading = false;
    }

    // Breadcrumb methods
    async breadcrumb_label() {
        return 'Dashboard';
    }

    async breadcrumb_label_active() {
        return 'Overview of your business at a glance';
    }
}
