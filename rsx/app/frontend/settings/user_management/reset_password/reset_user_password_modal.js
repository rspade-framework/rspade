/**
 * Reset_User_Password_Modal - an administrator sets a TEMPORARY password for another user.
 *
 * Asks the administrator to confirm it is them first (Password_Confirmation_Modal - the
 * endpoint refuses without a recent confirmation), then opens the form. The dialog opens
 * immediately; Reset_User_Password_Modal_Form loads what it needs behind the spinner. On
 * success the password the administrator typed is shown once more, to read out, with what
 * happens next.
 *
 * Returns the endpoint's result on success, false on cancel.
 */
class Reset_User_Password_Modal extends Modal_Abstract {
    /**
     * @param {number} user_id - the user whose password is set
     * @returns {Promise<Object|false>}
     */
    static async show(user_id) {
        if (!await Password_Confirmation_Modal.require()) {
            return false;
        }

        let password = '';

        const result = await Modal.form({
            title: 'Reset Password',
            component: 'Reset_User_Password_Modal_Form',
            component_args: { user_id: int(user_id) },
            submit_label: 'Set Password',
            spinner_until_ready: 'always',
            before_submit: (vals) => {
                password = str(vals.new_password);
                return vals;
            },
        });

        if (!result) {
            return false;
        }

        await Modal.alert(
            'Password Set',
            'Read this password to the user:\n\n' + password
                + '\n\nThey will be asked to choose a new password the next time they sign in. They have been signed out everywhere.'
        );

        return result;
    }
}
