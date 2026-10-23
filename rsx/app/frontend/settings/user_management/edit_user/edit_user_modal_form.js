/**
 * Edit_User_Modal_Form
 *
 * The body of Edit_User_Modal. The record loads in on_load() and reaches the form as its
 * $data; the dialog shows its spinner over this component until it is ready
 * (Modal.form's spinner_until_ready), so the form is never seen - or submitted - before
 * its values are final.
 *
 * A record the signed-in user may not edit (the endpoint refuses it) is rendered as the
 * form's own error once the dialog reveals it.
 */
class Edit_User_Modal_Form extends Component {
    on_create() {
        this.data.record = null;
        this.data.error_data = null;
    }

    async on_load() {
        try {
            this.data.record = await Frontend_Settings_User_Management_Controller.get_user_for_edit({
                user_id: int(this.args.user_id),
            });
        } catch (e) {
            this.data.error_data = e;
        }
    }

    on_ready() {
        if (this.data.error_data) {
            this.sid('form').render_error(this.data.error_data);
        }
    }
}
