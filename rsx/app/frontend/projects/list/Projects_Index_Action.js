/**
 * Projects list page action
 *
 * Displays the projects DataGrid with filtering and search.
 */
@route('/projects')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Projects')
@auth('is_logged_in', 'can_view_data')
class Projects_Index_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    async on_load() {
        // DataGrid will load its own data via Ajax
        // No initial data loading needed here
    }

    // Breadcrumb methods
    async breadcrumb_label() {
        return 'Projects';
    }

    async breadcrumb_label_active() {
        return 'Track and manage your projects';
    }

    // Action buttons for page header
    page_actions() {
        // The Export button mirrors the endpoint's own 'can_export_data' gate
        // (PERM_DATA_EXPORT) - the header never offers what the server will refuse.
        const can_export = Permission.has_permission(User_Model.PERM_DATA_EXPORT);

        return {
            component: 'List_Page_Actions',
            args: {
                new_href: Rsx.Route('Projects_Edit_Action'),
                on_export: can_export ? () => this.export_all() : null,
                reports: true,
            },
        };
    }

    /**
     * "Export what I'm looking at": the whole filtered set, ticked rows or not.
     */
    export_all() {
        const grid = this.sid('grid');
        grid.export_selection(grid.whole_set_selection());
    }
}
