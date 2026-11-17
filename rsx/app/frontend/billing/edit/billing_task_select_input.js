/**
 * Billing_Task_Select_Input - the entry form's Task select, whose choices are the tasks of the
 * project chosen beside it.
 *
 * Select_Input with one addition, set_options(): the edit action hands it the new project's
 * tasks when the USER changes the project. The task held until then belongs to the old
 * project, so it is cleared AS THE USER'S CHANGE (announced) - a programmatic clear would
 * leave the field "unchanged", and an edit save would keep the old project's task.
 */
class Billing_Task_Select_Input extends Select_Input {
    /**
     * Replace the choices with $options and clear the selection.
     *
     * @param {Array<{value: *, label: string}>} options
     */
    set_options(options) {
        const had = this._get_value();

        this.args.options = options;
        if (this.tom_select) {
            this.tom_select.clear(true);
            this.tom_select.clearOptions();
            for (const opt of options) {
                this.tom_select.addOption({ value: str(opt.value), text: opt.label });
            }
            this.tom_select.refreshOptions(false);
        }

        if (had !== '' && had !== null && had !== undefined) {
            this._notify_input(this._get_value());
        }
    }
}
