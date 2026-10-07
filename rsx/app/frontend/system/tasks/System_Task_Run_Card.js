/**
 * System_Task_Run_Card - see the .jqhtml. Clicking the head opens or closes the card; the
 * buttons call Rsx_Task, which the application's control gate allows or refuses.
 */
class System_Task_Run_Card extends Component {
    on_create() {
        this.state.open = !!this.args.open;
    }

    on_ready() {
        const that = this;

        this.$sid('toggle').off('click.toggle').on('click.toggle', function () {
            that.state.open = !that.state.open;
            that.render();
        });

        this.$.off('click.run_card').on('click.run_card', '[data-task-action]', async function () {
            const $element = $(this);
            const action = $element.attr('data-task-action');

            if (action === 'stop') {
                await Rsx_Task.stop(that.args.task.id);
                Flash_Alert.success('Asked task #' + that.args.task.id + ' to stop.');
            } else if (action === 'rerun') {
                const result = await Rsx_Task.rerun(that.args.task.id);
                Flash_Alert.success('Started again as task #' + result.task_id + '.');
            }
        });
    }
}
