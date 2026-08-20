<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Services;

use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;
use Rsx\Models\Portal_Invitation_Model;

/**
 * Portal_Invitation_Service - scheduled maintenance for portal invitations.
 *
 * The hourly expiry task flips still-pending invitations that have passed their
 * expiry window to EXPIRED, so following the link lands the user on the "expired,
 * you can still create an account" page rather than a dead "invalid" error, and so
 * admin screens show an accurate status. Invites default to a 14-day window
 * (config rsx.portal.invitation_expiry_days).
 */
class Portal_Invitation_Service extends Rsx_Service_Abstract
{
    /**
     * Expire stale (past-window) pending invitations.
     */
    #[Task('Expire stale portal invitations')]
    #[Schedule('0 * * * *')]
    public static function expire_stale(Task_Instance $task, array $params = [])
    {
        $expired = Portal_Invitation_Model::where('status_id', Portal_Invitation_Model::STATUS_PENDING)
            ->where('expires_at', '<', now())
            ->update(['status_id' => Portal_Invitation_Model::STATUS_EXPIRED]);

        if ($expired > 0) {
            $task->info("Expired {$expired} stale portal invitation(s).");
        }

        return ['expired' => $expired];
    }
}
