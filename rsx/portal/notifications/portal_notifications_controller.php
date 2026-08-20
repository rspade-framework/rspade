<?php

namespace Rsx\Portal\Notifications;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Portal_Notification_Model;
use Rsx\Portal_Permission;

/**
 * Portal_Notifications_Controller - the portal-authed read/mark-read endpoint for
 * the activity/notification feed (T4). The T5 dashboard feed UI consumes this; it
 * is pure presentation because every method here returns ONLY the calling portal
 * user's own feed and read-state.
 *
 * Authorization: the class-level #[Auth('is_logged_in')] gate (portal realm) admits
 * only a logged-in portal user. Every method derives the recipient from the session - it
 * never accepts a portal_user_id from the client - so a user can never read or
 * mutate another user's notifications.
 */
#[Auth('is_logged_in')]
class Portal_Notifications_Controller extends Rsx_Controller_Abstract
{
    /**
     * The current portal user's feed plus unread count.
     *
     * Params (optional):
     *   limit       int  - max rows (default 50)
     *   unread_only bool - only unread
     *   since       ISO  - created_at > since ("what's new since last login")
     */
    #[Ajax_Endpoint]
    public static function feed(Request $request, array $params = [])
    {
        $portal_user_id = Portal_Permission::current_user_id();

        $options = [];
        if (isset($params['limit'])) {
            $options['limit'] = (int) $params['limit'];
        }
        if (!empty($params['unread_only'])) {
            $options['unread_only'] = true;
        }
        if (!empty($params['since'])) {
            $options['since'] = $params['since'];
        }

        $rows = Portal_Notification_Model::feed($portal_user_id, $options);

        $notifications = [];
        foreach ($rows as $row) {
            $notifications[] = [
                'id' => $row->id,
                'type' => $row->type,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'payload' => $row->payload,
                'read_at' => $row->read_at,
                'created_at' => $row->created_at,
            ];
        }

        return [
            'notifications' => $notifications,
            'unread_count' => Portal_Notification_Model::unread_count($portal_user_id),
        ];
    }

    /**
     * Unread count only (cheap polling / badge).
     */
    #[Ajax_Endpoint]
    public static function unread_count(Request $request, array $params = [])
    {
        $portal_user_id = Portal_Permission::current_user_id();

        return ['unread_count' => Portal_Notification_Model::unread_count($portal_user_id)];
    }

    /**
     * Mark one of the caller's notifications read.
     */
    #[Ajax_Endpoint]
    public static function mark_read(Request $request, array $params = [])
    {
        if (Portal_Permission::is_read_only()) {
            return response_unauthorized('This is a read-only session; changes are disabled.');
        }

        $notification_id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($notification_id <= 0) {
            return response_error(Ajax::ERROR_VALIDATION, 'Notification id is required');
        }

        $portal_user_id = Portal_Permission::current_user_id();
        $changed = Portal_Notification_Model::mark_read($portal_user_id, $notification_id);

        return [
            'changed' => $changed,
            'unread_count' => Portal_Notification_Model::unread_count($portal_user_id),
        ];
    }

    /**
     * Mark all of the caller's notifications read.
     */
    #[Ajax_Endpoint]
    public static function mark_all_read(Request $request, array $params = [])
    {
        if (Portal_Permission::is_read_only()) {
            return response_unauthorized('This is a read-only session; changes are disabled.');
        }

        $portal_user_id = Portal_Permission::current_user_id();
        $count = Portal_Notification_Model::mark_all_read($portal_user_id);

        return [
            'count' => $count,
            'unread_count' => Portal_Notification_Model::unread_count($portal_user_id),
        ];
    }
}
