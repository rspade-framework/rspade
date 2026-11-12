/**
 * Reset_User_Password_Modal_Form
 *
 * The body of Reset_User_Password_Modal. Loads the target's name and sign-in address
 * (get_user_for_password_reset) behind the dialog's spinner; a user the caller may not reset
 * is rendered as the form's own error once the dialog reveals it.
 */
class Reset_User_Password_Modal_Form extends Component {
    on_create() {
        this.data.record = null;
        this.data.error_data = null;
    }

    async on_load() {
        try {
            this.data.record = await Frontend_Settings_User_Management_Controller.get_user_for_password_reset({
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
