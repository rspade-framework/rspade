/**
 * Portal Dashboard Action
 *
 * The portal landing page is the activity feed (AP-B / T5): "what's new" for the
 * logged-in portal user. It is pure presentation over the T4 notification endpoint
 * (Portal_Notifications_Controller) - every method there derives the recipient from
 * the portal session, so this UI never passes a user id and can only ever show the
 * caller's own feed.
 *
 * Data flow (jqhtml lifecycle): on_load() fetches the feed + unread count into
 * this.data; the template renders directly from this.data. Mark-read mutations go
 * through the controller and then this.reload() re-fetches - we never write into
 * this.data from an event handler.
 *
 * Realtime live-refresh: on_create() subscribes to Portal_Notification_Topic filtered
 * to the logged-in portal user's own id, gating the first load on establishment and
 * refreshing the feed on every emit.
 * Portal_Notification_Model::emit() publishes on that topic per recipient, and
 * Portal_Notification_Topic::can_subscribe() authorizes only the owning portal user
 * (filter identity == session identity). The subscription is idempotent per component
 * and auto-stops on destroy.
 */

@route('/')
@route('/dashboard')
@layout('Portal_Layout')
@portal_spa('Portal_Spa_Controller::index')
@title('Dashboard')
@auth('is_logged_in')
class Portal_Dashboard_Action extends Spa_Action {
    // Composes with Page_Scaffold; Portal_Layout yields page width/padding to it.
    scaffolded = true;

    on_create() {
        this.data.loading = true;
        this.data.notifications = [];
        this.data.unread_count = 0;
        this.data.pending_invitations = [];
        this.data.has_client_access = false;
        this.data.workspaces = [];
        this.data.action_threads = [];

        // Canonical realtime pattern: subscribe in on_create() so the FIRST on_load() is
        // GATED on subscription establishment -> a single race-free fetch of the feed (the
        // initial resync is swallowed, the gated load IS the revalidation). The topic
        // authorizes only the owning portal user (filter identity == session), so we pass
        // our own id from the portal session surface (this action is auth-gated, so the
        // portal user always exists - fail loud otherwise). refresh() re-renders only if the
        // feed data changed. Idempotent per component; auto-stops on destroy.
        this.subscribe('Portal_Notification_Topic', {portal_user_id: int(Rsx_Portal.user().id)}, () => this.refresh());
    }

    async on_load() {
        const [feed, invites, workspaces, action_threads] = await Promise.all([
            Portal_Notifications_Controller.feed({ limit: 50 }),
            Portal_Invitations_Controller.pending(),
            Portal_Workspaces_Controller.list(),
            Portal_Request_Threads_Controller.needs_response_for_user(),
        ]);
        this.data.notifications = feed.notifications;
        this.data.unread_count = feed.unread_count;
        this.data.pending_invitations = invites.invitations;
        this.data.has_client_access = invites.has_client_access;
        this.data.workspaces = workspaces.workspaces;
        this.data.action_threads = action_threads.threads;
        this.data.loading = false;
    }

    on_ready() {
        // Accept / decline a pending per-client invitation, then refresh. Binds are
        // namespaced + idempotent (on_ready re-fires after each reload()).
        this.$.off('click.accept').on('click.accept', '[data-accept-invite]', async (e) => {
            const invitation_id = int($(e.currentTarget).data('accept-invite'));
            try {
                await Portal_Invitations_Controller.accept({ invitation_id });
            } catch (err) {
                await Modal.alert('Error', err.message || 'Failed to accept invitation');
            }
            this.reload();
        });

        this.$.off('click.decline').on('click.decline', '[data-decline-invite]', async (e) => {
            const invitation_id = int($(e.currentTarget).data('decline-invite'));
            const confirmed = await Modal.confirm('Decline Invitation', 'Decline this invitation? You can be re-invited later.', 'Decline', 'Cancel');
            if (!confirmed) return;
            try {
                await Portal_Invitations_Controller.decline({ invitation_id });
            } catch (err) {
                await Modal.alert('Error', err.message || 'Failed to decline invitation');
            }
            this.reload();
        });

        // Mark a single item read, then navigate if it carries a url. Marking is a
        // mutation -> reload() to refresh this.data rather than mutating it here.
        this.$.off('click.notif').on('click.notif', '[data-notification-id]', async (e) => {
            const $item = $(e.currentTarget);
            const id = int($item.data('notification-id'));
            const url = $item.data('notification-url');
            const is_unread = $item.data('notification-unread') === true || $item.data('notification-unread') === 'true';

            if (url) {
                // Mark read in the background, then navigate. No reload needed - we
                // are leaving the page.
                if (is_unread) {
                    await Portal_Notifications_Controller.mark_read({ id });
                }
                Spa.dispatch(url);
                return;
            }

            // No url: toggle to read and refresh the feed in place.
            if (is_unread) {
                await Portal_Notifications_Controller.mark_read({ id });
                this.reload();
            }
        });

        // Mark everything read, then refresh. The button lives in a Section's named
        // actions slot, so it is reached by a `.js-*` class hook (not $sid, which is
        // unproven across a named-slot boundary), delegated on this.$.
        this.$.off('click.markall').on('click.markall', '.js-mark-all-read', async () => {
            await Portal_Notifications_Controller.mark_all_read();
            this.reload();
        });
    }
}
