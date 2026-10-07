/**
 * System_Tasks_Action - the signed-in user's background task runs. See the .jqhtml.
 *
 * The list is the newest page of Rsx_Task.page({}) - the framework narrows it to the runs this user may see
 * (the application's task.view.scope handler) - and reloads on Task_List_Changed_Topic.
 */
@route('/frontend/system/tasks')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Background Tasks')
@auth('is_logged_in', 'can_manage_site_settings')
class System_Tasks_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.tasks = null;
        this.data.error_data = null;
        this.state.open_id = null;

        const that = this;
        const reload_soon = debounce(() => that.refresh(), Rsx_Task.LIVE_UPDATE_DELAY);
        this.subscribe('Task_List_Changed_Topic', {}, () => reload_soon());
    }

    async on_load() {
        try {
            const response = await Rsx_Task.page({}, null, 50);
            this.data.tasks = response.tasks;
        } catch (e) {
            this.data.error_data = e;
        }
    }

    on_ready() {
        const that = this;

        this.$sid('start').off('click.start').on('click.start', async function () {
            const result = await System_Tasks_Controller.start_showcase();
            that.state.open_id = result.task_id;
            that.reload();
        });
    }

    async breadcrumb_label_active() { return 'Background Tasks'; }
    async breadcrumb_parent() { return Rsx.Route('System_Status_Action'); }
}
