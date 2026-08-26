/**
 * Projects list page action
 *
 * Displays the projects DataGrid with filtering and search.
 */
@route('/projects')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Projects')
@auth('is_logged_in')
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

    /**
     * The page-header Export button lives in the LAYOUT's header, not in this action's own
     * element, so it cannot be reached with $sid(). One delegated handler namespaced to this
     * component instance, dropped again in on_stop(), is how rsx/lib/modal reaches the same
     * kind of out-of-tree element.
     */
    on_ready() {
        let that = this;

        $(document).on('click.page_export_' + that._cid, '.js-page-export', function (e) {
            e.preventDefault();

            // "Export what I'm looking at": the whole filtered set, ticked rows or not.
            const grid = that.sid('grid');
            grid.export_selection(grid.whole_set_selection());
        });
    }

    on_stop() {
        $(document).off('click.page_export_' + this._cid);
    }

    // Action buttons for page header
    page_actions() {
        // The Export button mirrors the endpoint's own 'can_export_data' gate
        // (PERM_DATA_EXPORT) - the header never offers what the server will refuse.
        const can_export = Permission.has_permission(User_Model.PERM_DATA_EXPORT);

        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Projects_Edit_Action')}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> New
                </a>
                ${can_export ? `
                <button class="btn btn-secondary btn-sm js-page-export">
                    <i class="bi bi-download"></i> Export
                </button>
                ` : ''}
                <button class="btn btn-secondary btn-sm">
                    <i class="bi bi-file-earmark-bar-graph"></i> Reports
                </button>
            </div>
        `;
    }
}
