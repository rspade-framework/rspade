/**
 * Action Log detail view action
 *
 * Displays detailed information about a single action log entry,
 * including metadata and related entities.
 */
@route('/action-logs/view/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Action_Logs_View_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    on_create() {
        // Initialize data to prevent undefined errors during first render
        this.data.log = {};
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            // Load action log data via ORM fetch
            this.data.log = await Action_Log_Model.fetch(this.args.id);
        } catch (e) {
            // Store error for Universal_Error_Page_Component to handle
            this.data.error_data = e;
        }
        this.data.loading = false;
    }

    // Breadcrumb methods
    async page_title() {
        await this.await_loaded();
        return this.data.log.type_id__label || 'Action Log';
    }

    async breadcrumb_label() {
        await this.await_loaded();
        return this.data.log.type_id__label || 'Action Log';
    }

    async breadcrumb_label_active() {
        return 'View Action Details';
    }

    async breadcrumb_parent() {
        return Rsx.Route('Action_Logs_Index_Action');
    }

    // Action buttons for page header - back button only (no edit/delete)
    page_actions() {
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Action_Logs_Index_Action')}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        `;
    }
}
