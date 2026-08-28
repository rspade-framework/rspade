/**
 * Edit_Group_Modal_Form
 *
 * The body of Edit_Group_Modal, and the worked example of the EDIT-MODAL loading
 * procedure (the page equivalent lives in the *_Edit_Action classes).
 *
 * Three hooks, each for a definitional reason:
 *
 *  - on_create() STARTS the record fetch. It is the earliest legal moment, so the
 *    request races this component's own on_load() (the member list) and the children's
 *    initialization instead of queueing behind them. Starting a promise and stashing
 *    it on the instance is legal here; awaiting it is not.
 *  - on_render() ARMS the overlay while the record has not settled. Renders REBUILD
 *    the DOM, so an overlay drawn once imperatively would silently disappear on the
 *    next render - it has to be state, redrawn every time.
 *  - on_ready() (guarded to run once) consumes the promise through form.populate(),
 *    which applies the values and clears the overlay in its own finally, on success
 *    and on failure alike.
 *
 * The settled flag is an INSTANCE property, deliberately not this.data: this.data is
 * the maybe-cached result of on_load(), and a cached "already settled" would lie on
 * the next open while the real fetch was still in flight.
 */
class Edit_Group_Modal_Form extends Component {

    on_create() {
        // Rendered before on_load() completes, so the option list needs a shape now.
        this.data.user_options = [];

        this._record = Frontend_Settings_Group_Management_Controller.get_group_for_edit({
            group_id: int(this.args.group_id),
        });
    }

    async on_load() {
        // The member checkboxes need their option list to render at all, so it is a
        // load result rather than part of the record.
        const users_data = await Frontend_Settings_Group_Management_Controller.get_selectable_users({
            group_id: int(this.args.group_id),
        });

        this.data.user_options = users_data.users.map(user => ({
            id: user.id,
            label: user.display_name,
            subtitle: !user.is_active ? 'inactive' : null,
        }));
    }

    on_render() {
        if (!this._record_settled) {
            this._get_form()?.set_loading(true);
        }
    }

    on_ready() {
        // Guarded: populate() can itself trigger a re-render, which reaches ready again.
        if (this._populate_started) {
            return;
        }
        this._populate_started = true;
        this._populate();
    }

    async _populate() {
        const form = this._get_form();

        try {
            await form.populate(this._record);
        } catch (e) {
            this._record_settled = true;
            await form.render_error(e);
            return;
        }

        this._record_settled = true;
    }

    /**
     * The hosted form component, or null before it renders.
     *
     * @returns {Component|null}
     */
    _get_form() {
        const $form = this.$.find('.Rsx_Form').first();
        return $form.exists() ? $form.component() : null;
    }
}
