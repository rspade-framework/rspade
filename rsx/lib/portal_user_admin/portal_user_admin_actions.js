/**
 * Portal_User_Admin_Actions
 *
 * Shared client-side handlers for the GLOBAL account-level portal-user ban toggle
 * (suspend / reactivate), used by the Settings > Portal Users admin screen. These
 * call shared endpoints on Frontend_Clients_Controller (the single server-side
 * implementation).
 *
 * Account suspend is the global "ban this account entirely" action - distinct from
 * the per-client "Disable Access" (remove a client membership) on the members
 * table. Force-password-reset was removed (login/forgot-password is self-service).
 *
 * Each method shows a confirmation, calls the endpoint, surfaces the result, and
 * resolves to true if the caller should reload its list (false if cancelled or
 * failed). Server enforces staff auth + site scoping; this is UI affordance only.
 */
class Portal_User_Admin_Actions {
    /**
     * Suspend a portal user (blocks login + terminates active portal sessions).
     *
     * @param {number} portal_user_id
     * @returns {Promise<boolean>} true if suspended (caller should reload)
     */
    static async suspend(portal_user_id) {
        const confirmed = await Modal.confirm(
            'Suspend Account',
            'Ban this portal account entirely?\n\nThey will be unable to log in to ANY client and active sessions end immediately. This is a global account ban, separate from per-client access. To remove access to a single client, use Disable Access on that client\'s members table instead.',
            'Suspend Account',
            'Cancel'
        );
        if (!confirmed) return false;

        try {
            await Frontend_Clients_Controller.portal_user_suspend({portal_user_id});
            return true;
        } catch (e) {
            await Modal.alert('Error', e.message || 'Failed to suspend portal user');
            return false;
        }
    }

    /**
     * Reactivate a suspended portal user (restores login ability).
     *
     * @param {number} portal_user_id
     * @returns {Promise<boolean>} true if reactivated (caller should reload)
     */
    static async reactivate(portal_user_id) {
        const confirmed = await Modal.confirm(
            'Reactivate Portal User',
            'Reactivate this portal user?\n\nThey will be able to log in again.',
            'Reactivate',
            'Cancel'
        );
        if (!confirmed) return false;

        try {
            await Frontend_Clients_Controller.portal_user_reactivate({portal_user_id});
            return true;
        } catch (e) {
            await Modal.alert('Error', e.message || 'Failed to reactivate portal user');
            return false;
        }
    }
}
