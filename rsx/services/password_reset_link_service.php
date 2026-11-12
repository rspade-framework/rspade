<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Services;

use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;
use Rsx\Models\Login_User_Password_Reset_Model;
use Rsx\Models\Portal_Password_Reset_Model;

/**
 * Password_Reset_Link_Service - nightly removal of spent password reset links, staff and portal.
 *
 * A reset link is a row (login_user_password_resets / portal_password_resets, both over the
 * Password_Reset_Token trait). Once it is USED (completed, superseded by a newer request, or
 * ended by any password change) or EXPIRED, it can never open anything again - and a spent link
 * answers the same "invalid link" page as one that never existed - so it is deleted here.
 * Password_Reset_Token::query_spent() is the rule; each delete restates it, so a row is removed
 * only while it is still spent. A live link is never touched.
 *
 * The staff table is not site-scoped (a login identity spans every site): its rows are deleted
 * in batches with no site lock. The portal table is site-scoped: each site is declared in turn
 * and each batch is deleted in its own Site_Lock::write() section.
 */
class Password_Reset_Link_Service extends Rsx_Service_Abstract
{
    /**
     * Spent links are deleted in batches of this many rows: a delete that restates the whole
     * rule needs no per-row decision, and a batch this size commits in a fraction of a second,
     * so a portal reset request queued behind a portal batch's write lock is barely held back.
     */
    const SWEEP_BATCH_SIZE = 500;

    /**
     * Delete every used or expired password reset link, staff and portal (every site).
     *
     * Reports each phase, progress over the portal sites, a stdout line, and as its state
     * {deleted_staff, deleted_portal} plus a one-line summary.
     */
    #[Task('Delete used and expired password reset links')]
    #[Exclusive('maintenance')]
    #[Schedule('maintenance')]
    public static function sweep(Task_Instance $task, array $params = [])
    {
        $task->status('Staff: deleting used and expired password reset links');
        $deleted_staff = static::__sweep_batches(
            $task,
            Login_User_Password_Reset_Model::class,
            fn (array $ids) => Login_User_Password_Reset_Model::query_spent()->whereIn('id', $ids)->delete()
        );

        // Every site: retention is install policy, and the worker running this pass serves no
        // tenant. Each site is declared in turn, so the reads below are that site's, and it
        // changes only between write sections.
        $site_ids = [];
        foreach (Site_Model::query()->orderBy('id')->result_set() as $site) {
            $site_ids[] = (int) $site->id;
        }

        $deleted_portal = 0;
        $site_count = count($site_ids);
        foreach ($site_ids as $index => $site_id) {
            if ($task->is_stop_requested()) {
                break;
            }

            Session::set_temporary_site_id($site_id);
            try {
                $task->status("Site {$site_id}: deleting used and expired portal password reset links");
                $deleted_portal += static::__sweep_batches(
                    $task,
                    Portal_Password_Reset_Model::class,
                    fn (array $ids) => (int) Site_Lock::write(
                        $site_id,
                        fn () => Portal_Password_Reset_Model::query_spent()->whereIn('id', $ids)->delete(),
                        'Delete spent portal password reset links'
                    )
                );
            } finally {
                Session::clear_temporary_site_id();
            }

            $task->progress_count($index + 1, $site_count);
        }

        $deleted = $deleted_staff + $deleted_portal;

        $task->stdout($deleted > 0
            ? "Deleted {$deleted_staff} staff and {$deleted_portal} portal password reset link(s), used or expired."
            : 'No password reset link is used or expired; nothing deleted.');

        $task->state(['deleted_staff' => $deleted_staff, 'deleted_portal' => $deleted_portal]);
        $task->summary("Deleted {$deleted_staff} staff and {$deleted_portal} portal used or expired password reset link(s).");

        return null;
    }

    /**
     * Read the spent links of $model_class (the declared site's, for a site-scoped model) and
     * hand their ids to $delete a batch at a time. $delete restates the rule and answers how many
     * it removed.
     *
     * @param Task_Instance $task
     * @param string $model_class Login_User_Password_Reset_Model or Portal_Password_Reset_Model
     * @param callable $delete fn (int[] $ids): int
     * @return int Rows deleted
     */
    private static function __sweep_batches(Task_Instance $task, string $model_class, callable $delete): int
    {
        $deleted = 0;
        $batch = [];

        foreach ($model_class::query_spent()->result_set() as $reset) {
            $task->heartbeat();
            if ($task->is_stop_requested()) {
                break;
            }

            $batch[] = (int) $reset->id;
            if (count($batch) >= self::SWEEP_BATCH_SIZE) {
                $deleted += (int) $delete($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $deleted += (int) $delete($batch);
        }

        return $deleted;
    }
}
