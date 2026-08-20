/**
 * Notification_Dropdown - Bell icon with dropdown notification list
 *
 * Displays unread notification count and provides dropdown with recent notifications.
 * Automatically refreshes count on SPA navigation.
 *
 * Features:
 * - Badge showing unread count (hides when 0)
 * - Dropdown with notification list
 * - Mark all as read
 * - Click notification to navigate and mark as read
 * - Auto-refresh on SPA dispatch
 */
class Notification_Dropdown extends Component {
    on_create() {
        this.state = {
            unread_count: 0,
            notifications: [],
            total: 0,
            loading: false,
            dropdown_loaded: false, // Track if we've loaded dropdown content
        };

        // Listen for SPA navigation to refresh count
        $(document).on('spa:dispatch', () => this._refresh_count());
    }

    async on_load() {
        const result = await Frontend_Notifications_Controller.get_count();
        this.data.initial_count = result.count;
    }

    on_ready() {
        if (this.data.initial_count > 0) {
            this.state.unread_count = this.data.initial_count;
            this.render();
        }
    }

    /**
     * Refresh the unread notification count
     */
    async _refresh_count() {
        try {
            const result = await Frontend_Notifications_Controller.get_count();
            this.state.unread_count = result.count;
            this.render();
        } catch (e) {
            console.warn('Failed to load notification count:', e);
        }
    }

    /**
     * Load notifications for dropdown display
     */
    async _load_dropdown() {
        if (this.state.loading) return;

        this.state.loading = true;
        this.render();

        try {
            const result = await Frontend_Notifications_Controller.get_dropdown({ limit: 5 });
            this.state.notifications = result.notifications;
            this.state.total = result.total;
            this.state.unread_count = result.unread;
            this.state.dropdown_loaded = true;
        } catch (e) {
            console.warn('Failed to load notifications:', e);
        } finally {
            this.state.loading = false;
            this.render();
        }
    }

    /**
     * Handle toggle button click - load dropdown on first open
     */
    _on_toggle_click(e) {
        // Load dropdown content on first open
        if (!this.state.dropdown_loaded) {
            this._load_dropdown();
        }
    }

    /**
     * Handle notification item click
     */
    _on_notification_click(e) {
        const $item = $(e.currentTarget);
        const notification_id = $item.data('notification-id');
        const url = $item.attr('href');

        // Mark as read (fire and forget)
        if (notification_id) {
            Frontend_Notifications_Controller.mark_read({ id: notification_id }).catch(() => {});
        }

        // If no URL or just #, prevent navigation and just close dropdown
        if (!url || url === '#') {
            e.preventDefault();
            return;
        }

        // Let the click proceed - SPA will handle navigation
        // Close dropdown (Bootstrap handles this automatically)
    }

    /**
     * Handle mark all read click
     */
    async _on_mark_all_read(e) {
        e.preventDefault();

        try {
            await Frontend_Notifications_Controller.mark_all_read();
            // Refresh dropdown to update UI
            await this._load_dropdown();
        } catch (e) {
            console.warn('Failed to mark notifications as read:', e);
        }
    }
}
