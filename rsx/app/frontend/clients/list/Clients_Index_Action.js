/**
 * Clients list page action
 *
 * Displays the clients DataGrid with filtering and search.
 */
@route('/clients')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Clients')
@auth('is_logged_in')
class Clients_Index_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    // Breadcrumb methods
    async breadcrumb_label() {
        return 'Clients';
    }

    async breadcrumb_label_active() {
        return 'Manage your client accounts';
    }

    // Action buttons for page header
    page_actions() {
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Clients_Edit_Action')}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> New
                </a>
                <button class="btn btn-secondary btn-sm">
                    <i class="bi bi-download"></i> Export
                </button>
                <button class="btn btn-secondary btn-sm">
                    <i class="bi bi-file-earmark-bar-graph"></i> Reports
                </button>
            </div>
        `;
    }
}
