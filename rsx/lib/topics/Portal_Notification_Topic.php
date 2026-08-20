<?php

namespace Rsx\Lib\Topics;

use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Realtime\Realtime_Topic_Abstract;

/**
 * Portal_Notification_Topic - realtime topic for the portal activity/notification
 * primitive (T4). Portal_Notification_Model::emit() publishes one message per
 * recipient on this topic; the recipient's browser reacts by re-fetching its feed
 * through the portal endpoint (notification-only - no confidential data travels on
 * the wire).
 *
 * A subscription is filtered by portal_user_id. can_subscribe() authorizes ONLY
 * the owning portal user: the requester must be logged into the portal and the
 * filter's portal_user_id must be their own id. Fail-closed.
 */
class Portal_Notification_Topic extends Realtime_Topic_Abstract
{
    /**
     * Only the owning portal user may subscribe to their own notification stream.
     *
     * @param array $filter Expected: ['portal_user_id' => <id>]
     * @return bool
     */
    public static function can_subscribe(array $filter = []): bool
    {
        if (!Portal_Session::is_logged_in()) {
            return false;
        }

        $requested = isset($filter['portal_user_id']) ? (int) $filter['portal_user_id'] : 0;
        if ($requested <= 0) {
            return false;
        }

        return $requested === (int) Portal_Session::get_portal_user_id();
    }
}
