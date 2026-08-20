/**
 * Tasks Index Action
 *
 * Task list page: the standard thin datagrid wrapper (Page_Scaffold + Tasks_DataGrid)
 * matching Projects_Index. New Task lives in the page header actions.
 */
@route('/tasks')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Tasks')
@auth('is_logged_in')
class Tasks_Index_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page padding
    // to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    async on_load() {
        // DataGrid loads its own data via Ajax; nothing to preload here.
    }

    // Breadcrumb methods
    async breadcrumb_label() {
        return 'Tasks';
    }

    async breadcrumb_label_active() {
        return 'Manage your task list';
    }

    // Action buttons for page header
    page_actions() {
        return `
            <div class="d-flex gap-2">
                <a href="${Rsx.Route('Tasks_Edit_Action')}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> New Task
                </a>
            </div>
        `;
    }
}
