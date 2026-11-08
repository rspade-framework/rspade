/**
 * Mass_Edit_Progress_Modal - follow a mass-edit run until it ends.
 *
 * A mass edit (a datagrid footer action applied to a selection) runs as a task: its endpoint
 * validates the selection, dispatches the run and returns {task_id}. This dialog shows the
 * run's progress and closes itself when the run ends, resolving with the run's status array
 * (status_id, status, error, ...). "Continue in background" closes it early and resolves
 * false: the run keeps going, and the caller says so.
 *
 *     const result = await Frontend_Clients_Controller.bulk_delete(payload);
 *     const status = await Mass_Edit_Progress_Modal.show(result.task_id, 'Deleting clients');
 *     if (!status) {
 *         Flash_Alert.info('Still running in the background.');
 *     } else if (Mass_Edit_Progress_Modal.failed(status)) {
 *         Flash_Alert.error(await Mass_Edit_Progress_Modal.summary(status));
 *     } else {
 *         Flash_Alert.success(await Mass_Edit_Progress_Modal.summary(status));
 *     }
 */
class Mass_Edit_Progress_Modal extends Modal_Abstract {
    /**
     * @param {number} task_id - the run's id
     * @param {string} title - what the run is doing ("Deleting clients")
     * @returns {Promise<Object|false>} the finished run's status, or false if closed early
     */
    static async show(task_id, title) {
        const $body = $('<div>');
        let modal = null;
        let finished = null;

        await Modal.show({
            title: title,
            body: $body,
            max_width: 480,
            buttons: [
                { label: 'Continue in background', value: false, class: 'btn-secondary', default: true },
            ],
            on_show: () => {
                modal = Modal.get_current();
                const progress = $body.component('Mass_Edit_Progress', { task_id: int(task_id) }).component();
                progress.on('finished', (component, status) => {
                    finished = status;
                    modal.close(status);
                });
            },
        });

        return finished || false;
    }

    /**
     * Did the run end in failure (it threw, returned a failure, or was killed)?
     *
     * @param {Object} status - the status array show() resolved with
     * @returns {boolean}
     */
    static failed(status) {
        return Rsx_Task.tone(status.status_id) === 'danger';
    }

    /**
     * The run's own one-line summary, or its failure, for a flash message.
     *
     * @param {Object} status - the status array show() resolved with
     * @returns {Promise<string>}
     */
    static async summary(status) {
        const report = await Rsx_Task.report(status.id, 'summary');

        return report.value || status.error || status.status_text || status.status;
    }
}
