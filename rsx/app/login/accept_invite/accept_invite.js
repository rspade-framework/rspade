/**
 * Accept_Invite
 *
 * Handles invitation acceptance workflow
 */
class Accept_Invite {
    /**
     * Initialize when app is ready
     * Called automatically by RSX framework
     */
    static on_app_ready() {
        // Only initialize if we're on this view
        if (!$('.Accept_Invite').exists()) {
            return;
        }

        // Bind accept button click handler
        $('#accept-btn').click(async () => {
            const code = $('#accept-btn').data('code');

            if (!code) {
                await Modal.alert('Error', 'No invitation code found');
                return;
            }

            try {
                const response = await Accept_Invite_Controller.accept({code: code});

                // The call resolves with the endpoint's return value; a failure rejects
                window.location.href = response.redirect_url;
            } catch (error) {
                await Modal.alert('Error', error.message || 'An error occurred while accepting the invitation');
            }
        });
    }
}
