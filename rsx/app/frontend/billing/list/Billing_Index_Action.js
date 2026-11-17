/**
 * Billing entries list - every billable entry (time, expenses, fixed fees) on the site.
 *
 * The header's "New entry" menu offers the three kinds (Billing_Index_Page_Actions); the grid
 * filters by status, by whose entries (mine / everyone's) and by project. The project choices
 * load here and are handed to the grid, which has no load of its own.
 */
@route('/billing')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Billing')
@auth('is_logged_in', 'can_view_data')
class Billing_Index_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.projects = [];
    }

    async on_load() {
        const options = await Frontend_Billing_Controller.form_options({});
        this.data.projects = options.projects.map((p) => ({
            value: p.id,
            label: p.client_name ? `${p.label} (${p.client_name})` : p.label,
        }));
    }

    async breadcrumb_label() {
        return 'Billing';
    }

    async breadcrumb_label_active() {
        return 'Time, expenses and fees';
    }

    page_actions() {
        return { component: 'Billing_Index_Page_Actions' };
    }
}
