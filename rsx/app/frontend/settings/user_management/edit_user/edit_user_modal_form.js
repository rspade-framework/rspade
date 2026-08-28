/**
 * Edit_User_Modal_Form
 *
 * The body of Edit_User_Modal. See Edit_Group_Modal_Form for the annotated walkthrough
 * of this procedure - the two are the same shape because every edit modal is:
 *
 *  - on_create() starts the fetch (earliest legal moment; it races the children's own
 *    initialization rather than queueing behind it),
 *  - on_render() arms the loading overlay while the record has not settled (renders
 *    rebuild the DOM, so the overlay must be state),
 *  - on_ready() applies the record through form.populate(), which clears the overlay
 *    in its finally whether the fetch succeeded or failed.
 *
 * _record_settled is an INSTANCE property, not this.data: this.data can be served from
 * cache, and a cached "settled" would lie on the next open.
 */
class Edit_User_Modal_Form extends Component {

    on_create() {
        this._record = Frontend_Settings_User_Management_Controller.get_user_for_edit({
            user_id: int(this.args.user_id),
        });
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
