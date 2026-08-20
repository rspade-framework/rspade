<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sms;

use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Sms_Queue_Service - processes the outgoing SMS queue (framework core).
 */
class Sms_Queue_Service extends Rsx_Service_Abstract
{
    /**
     * Send the pending outgoing SMS queue.
     *
     * IMPORTANT: REAL OUTBOUND SMS DELIVERY IS NOT YET IMPLEMENTED. This task
     * currently marks each pending message HELD_BACK - it records the attempt to the
     * sms_queue table (the body is stored verbatim) for developer review or an
     * app-side "SMS Transaction Log". It sends nothing to any SMS provider.
     *
     * FUTURE real-send design (see backlog "wire real SMTP/SMS send") - NOT built:
     * replace the held-back marking with a delivery loop:
     *   attempt provider send (Twilio/etc.)
     *     - success: $sms->mark_sent()
     *     - failure: $sms->mark_failed($error), with this policy -
     *         * a provider *connect* error (unreachable) does NOT count toward the
     *           strike limit - do not retry until >= 5 minutes since the last attempt;
     *         * an error returned by the provider (rejected number, etc.) DOES count;
     *         * give up (STATUS_FAILED) after config('rsx.sms.retry_max') attempts (default 6).
     *   That needs a `last_attempt_at` column (not added yet). The 5-minute cron then
     *   reprocesses retry-eligible failures. THERE IS NO RETRY LOOP HERE YET.
     *
     * #[Exclusive]: at most one drain runs at a time cluster-wide (coalesced). The
     * 5-minute cron reprocesses; app sends call Task::dispatch(
     * 'Sms_Queue_Service','send_pending_queue') via Rsx_Sms::send to drain promptly.
     */
    #[Task('Send pending outgoing SMS queue')]
    #[Exclusive]
    #[Schedule('*/5 * * * *')]
    public static function send_pending_queue(Task_Instance $task, array $params = []): array
    {
        $held_back = 0;

        // Drain one at a time until the queue is empty. mark_held_back() moves each
        // row out of PENDING, so get_pending() terminates the loop.
        while (true) {
            $queued = Sms_Queue_Model::get_pending(1)->first();
            if (!$queued) {
                break;
            }

            // TODO (backlog): attempt real provider delivery here; on success
            // mark_sent(), on failure mark_failed() per the policy above. For now we
            // only record the attempt as held-back (nothing is sent).
            $queued->mark_held_back('Delivery not implemented - recorded for developer review (held back in dev).');
            $task->info("Held back SMS #{$queued->id} to {$queued->to_number}");

            $held_back++;
        }

        return ['held_back' => $held_back];
    }

    /**
     * Delete old terminal (sent/failed/blocked/held-back) SMS records.
     */
    #[Task('Clean up old SMS queue records')]
    #[Schedule('0 3 * * *')]
    public static function cleanup(Task_Instance $task, array $params = []): array
    {
        $days = $params['days'] ?? 90;
        $deleted = Sms_Queue_Model::cleanup_old($days);
        $task->info("Deleted {$deleted} SMS records older than {$days} days");

        return ['deleted' => $deleted];
    }
}
