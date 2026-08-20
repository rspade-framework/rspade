<?php

namespace Rsx\App\Frontend\Notifications;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Throttle\Rsx_Throttle;
use Rsx\Lib\Notification\Notification;

/**
 * Frontend_Notifications_Controller - Ajax endpoints for notification management
 *
 * Provides endpoints for the notification dropdown UI:
 * - get_count: Get unread count (with throttled expiry check)
 * - get_dropdown: Get notifications for display
 * - mark_all_read: Mark all as read
 * - mark_read: Mark single notification as read
 */
#[Auth('is_logged_in')]
class Frontend_Notifications_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Get unread notification count
     *
     * Also performs throttled expiry check (every 30 minutes) to clean up old notifications.
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_count(Request $request, array $params = [])
    {
        $user_id = Session::get_user_id();

        // Throttled expiry check - run every 30 minutes per user
        if (Rsx_Throttle::check('NOTIFICATION_EXPIRE_CHECK', $user_id, minutes: 30)) {
            Notification::expire_old();
        }

        return [
            'count' => Notification::get_unread_count(),
        ];
    }

    /**
     * Ajax endpoint: Get notifications for dropdown display
     *
     * Returns notifications with rendered text, URL, and image URL.
     * Self-polices invalid notifications (deleted entities).
     *
     * @param Request $request
     * @param array $params ['limit' => int] Optional limit (default 5)
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_dropdown(Request $request, array $params = [])
    {
        $limit = (int)($params['limit'] ?? 5);
        $limit = max(1, min($limit, 20)); // Clamp between 1-20

        return Notification::get_for_dropdown($limit);
    }

    /**
     * Ajax endpoint: Mark all notifications as read
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function mark_all_read(Request $request, array $params = [])
    {
        $count = Notification::mark_all_read();

        return [
            'marked' => $count,
        ];
    }

    /**
     * Ajax endpoint: Mark a single notification as read
     *
     * @param Request $request
     * @param array $params ['id' => int] Notification ID
     * @return array
     */
    #[Ajax_Endpoint]
    public static function mark_read(Request $request, array $params = [])
    {
        $notification_id = (int)($params['id'] ?? 0);

        if (!$notification_id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Notification ID is required');
        }

        $success = Notification::mark_read($notification_id);

        if (!$success) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Notification not found');
        }

        return [
            'success' => true,
        ];
    }
}
