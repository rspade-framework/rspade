/**
 * Mass_Edit_Progress - watches one mass-edit run and announces its end.
 *
 * The two Task_Report widgets in the template follow the run on their own. This class only
 * answers "is it over?": on every Task_Changed_Topic frame (and once when ready, which also
 * covers a run that finished before the dialog opened) it asks Rsx_Task.get() and, the first
 * time the run is no longer live, triggers 'finished' with the run's status array.
 */
class Mass_Edit_Progress extends Component {
    on_create() {
        let that = this;

        that._finished = false;

        const check_soon = debounce(() => that._check_finished(), Rsx_Task.LIVE_UPDATE_DELAY);
        that.subscribe('Task_Changed_Topic', { id: int(that.args.task_id) }, () => check_soon());
    }

    async on_ready() {
        await this._check_finished();
    }

    async _check_finished() {
        if (this._finished) {
            return;
        }

        const status = await this.alive(Rsx_Task.get(this.args.task_id));

        if (status.is_live || this._finished) {
            return;
        }

        this._finished = true;
        this.trigger('finished', status);
    }
}
