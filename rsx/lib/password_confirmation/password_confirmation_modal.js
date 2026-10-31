/**
 * Password_Confirmation_Modal - this application's "confirm it's you" dialog.
 *
 * The framework requires a recent password confirmation before the actions that add or remove
 * a way into an account (rsx:man password_confirmation) and ships the form that collects it,
 * <Password_Confirmation_Form>. The dialog around it, and the rules for using it, are ours:
 *
 *   - ONE CALL in front of every gated button:
 *
 *         if (!await Password_Confirmation_Modal.require()) return;
 *         await Rsx_Two_Factor_Controller.recovery_regenerate();
 *
 *     It answers true at once while the page still holds a live confirmation, so a user
 *     doing several things in a row is asked once.
 *   - THREE TRIES. The third refused password closes the dialog and says so; the user is
 *     still signed in and can try again. The server's sign-in rate limit applies regardless.
 *   - A PASSKEY confirms too, from the form's own button, and closes the dialog.
 *   - CLEARED ON THE WAY OUT. A screen with gated buttons calls
 *     Rsx_Password_Confirmation.clear() from on_stop(), so the confirmation does not outlive
 *     the screen it was given for (the server's 5-minute expiry bounds it either way).
 *
 * Used by Password & Security and API Keys (staff) and the portal's settings.
 */
class Password_Confirmation_Modal extends Modal_Abstract {
    static MAX_ATTEMPTS = 3;

    /**
     * Make sure the user has confirmed recently, asking if they have not.
     *
     * @returns {Promise<boolean>} True when the gated action may go ahead.
     */
    static async require() {
        if (Rsx_Password_Confirmation.is_confirmed()) {
            return true;
        }

        const $body = $('<div>');
        let form = null;
        let refusals = 0;
        // While the Confirm button's own submit is running, its success is answered by the
        // button callback; the 'confirmed' listener below is for the passkey path only.
        let submitting = false;

        const result = await Modal.show({
            title: 'Confirm It\'s You',
            body: $body,
            max_width: 460,
            buttons: [
                { label: 'Cancel', value: false, class: 'btn-secondary' },
                {
                    label: 'Confirm',
                    class: 'btn-primary',
                    default: true,
                    callback: async function () {
                        submitting = true;
                        const confirmed = await form.submit();
                        submitting = false;

                        if (confirmed) {
                            return true;
                        }

                        refusals++;

                        // Only a literal false keeps a dialog open.
                        return refusals >= Password_Confirmation_Modal.MAX_ATTEMPTS ? 'exhausted' : false;
                    },
                },
            ],
            on_show: () => {
                form = $body.component('Password_Confirmation_Form').component();

                // A passkey confirms from the form's own button: close with success.
                form.on('confirmed', function () {
                    const current = Modal.get_current();
                    if (!submitting && current) {
                        current.close(true);
                    }
                });
            },
        });

        if (result === 'exhausted') {
            await Modal.alert(
                'Not Confirmed',
                'Your password could not be confirmed. You are still signed in - try again when you are ready.'
            );
            return false;
        }

        return result === true;
    }
}
