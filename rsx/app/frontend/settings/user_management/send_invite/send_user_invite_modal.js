/**
 * Send_User_Invite_Modal - Modal for resending a user invitation, and for showing one sent
 *
 * show(user_id) RESENDS: the backend mints a new invite code (ending the link the invitee
 * holds), emails it, and the dialog shows the new link. show_sent(invite_url) only shows a
 * link already sent - the add-user flow, whose endpoint sends the first invitation itself.
 *
 * show() returns invite data on success, false on error.
 */
class Send_User_Invite_Modal extends Modal_Abstract {
    /**
     * Resend a user's invitation and show its link
     *
     * @param {number} user_id - User ID to send invite to
     * @returns {Promise<Object|false>} Invite data on success, false on error
     */
    static async show(user_id) {
        try {
            // Call backend to send/resend invite
            const result = await Frontend_Settings_User_Management_Controller.send_invite({user_id});

            if (result.invite_url) {
                await Send_User_Invite_Modal.show_sent(result.invite_url);
            }

            return result;
        } catch (error) {
            // Show error to user
            await Modal.error(error, 'Failed to Send Invitation');
            return false;
        }
    }

    /**
     * Show the link of an invitation that has already been sent
     *
     * @param {string} invite_url - The invitation link the email carried
     */
    static async show_sent(invite_url) {
        // Create component instance (setter returns $element, call .component() to get instance)
        const $container = $('<div>');
        const invite_display = $container.component('Invite_Success_Modal_Body', {
            invite_url: invite_url
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
}
