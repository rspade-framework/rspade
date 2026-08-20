/**
 * Contacts list page action
 *
 * Displays the contacts DataGrid with filtering and search.
 */
@route('/contacts')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Contacts')
@auth('is_logged_in')
class Contacts_Index_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    async on_load() {
        // DataGrid will load its own data via Ajax
        // No initial data loading needed here
    }

    // Breadcrumb methods
    async breadcrumb_label() {
        return 'Contacts';
    }

    async breadcrumb_label_active() {
        return 'Manage your contact database';
    }

    // Action buttons for page header
    page_actions() {
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Contacts_Edit_Action')}" class="btn btn-primary btn-sm">
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
