/**
 * Invoices list - every invoice on the site, newest number first.
 *
 * An approver's screen: gated like the invoice endpoints (can_approve_billing), so its nav entry
 * and every link to it hide from everybody else. The header's New invoice button opens the
 * create flow; the grid filters by status (Overdue included) and by client. The client choices
 * load here and are handed to the grid, which has no load of its own.
 */
@route('/invoices')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Invoices')
@auth('is_logged_in', 'can_view_data', 'can_approve_billing')
class Invoices_Index_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        this.data.clients = [];
    }

    async on_load() {
        const options = await Frontend_Invoices_Controller.client_options({});
        this.data.clients = options.clients.map((c) => ({ value: c.id, label: c.label }));
    }

    async breadcrumb_label() {
        return 'Invoices';
    }

    async breadcrumb_label_active() {
        return 'All invoices';
    }

    page_actions() {
        return {
            component: 'List_Page_Actions',
            args: {
                new_href: Rsx.Route('Invoices_New_Action'),
                new_label: 'New invoice',
            },
        };
    }
}
