/**
 * Send_User_Invite_Modal - Modal for sending/resending user invitations
 *
 * Handles both initial invitation sending (after user creation) and resending
 * expired/pending invitations. Calls backend endpoint to generate new invite
 * code and displays URL for testing (until email system implemented).
 *
 * Returns invite data on success, false on error.
 */
class Send_User_Invite_Modal extends Modal_Abstract {
    /**
     * Show send invite modal and trigger backend invite
     *
     * @param {number} user_id - User ID to send invite to
     * @returns {Promise<Object|false>} Invite data on success, false on error
     */
    static async show(user_id) {
        try {
            // Call backend to send/resend invite
            const result = await Frontend_Settings_User_Management_Controller.send_invite({user_id});

            // Show invite URL for testing (until email system implemented)
            if (result.invite_url) {
                // Create component instance (setter returns $element, call .component() to get instance)
                const $container = $('<div>');
                const invite_display = $container.component('Invite_Success_Modal_Body', {
                    invite_url: result.invite_url
                }).component();

                // Wait for component to be ready
                await new Promise((resolve) => {
                    invite_display.on('ready', () => resolve());
                });

                await Modal.show({
                    title: 'User Invited Successfully',
                    body: invite_display.$,
                    buttons: [{
                        label: 'Close',
                        value: true,
                        class: 'btn-primary',
                        default: true
                    }],
                    max_width: 600,
                    closable: true,
                    close_on_submit: true
                });
            }

            return result;
        } catch (error) {
            // Show error to user
            await Modal.error(error, 'Failed to Send Invitation');
            return false;
        }
    }
}
