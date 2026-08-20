<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Mail_Queue_Service - processes the outgoing email queue (framework core).
 */
class Mail_Queue_Service extends Rsx_Service_Abstract
{
    /**
     * Send the pending outgoing email queue.
     *
     * IMPORTANT: REAL OUTBOUND DELIVERY (SMTP/SMS) IS NOT YET IMPLEMENTED. This task
     * currently RENDERS each pending email and marks it HELD_BACK - it records the
     * attempt to the email_queue table (with rendered_html, so a developer can
     * recover the content / a link that never left the box) for developer review or
     * an app-side "Email Transaction Log" UI. It delivers nothing.
     *
     * FUTURE real-send design (see backlog "wire real SMTP/SMS send") - NOT built:
     * replace the held-back marking with a delivery loop:
     *   render -> attempt SMTP send
     *     - success: $email->mark_sent()
     *     - failure: $email->mark_failed($error), with this policy -
     *         * an SMTP *connect* error (server unreachable) does NOT count toward
     *           the strike limit - just do not retry until >= 5 minutes since the
     *           last attempt;
     *         * an error produced by the SMTP *server* (e.g. 550 reject) DOES count;
     *         * give up (STATUS_FAILED) after config('rsx.mail.retry_max') attempts (default 6).
     *   That needs a `last_attempt_at` column (not added yet). The 5-minute cron
     *   then reprocesses retry-eligible failures. THERE IS NO RETRY LOOP HERE YET.
     *
     * #[Exclusive]: at most one drain runs at a time cluster-wide (coalesced). The
     * 5-minute cron reprocesses; app sends call Task::dispatch(
     * 'Mail_Queue_Service','send_pending_queue') via Rsx_Mail::send to drain
     * promptly after queueing.
     */
    #[Task('Send pending outgoing email queue')]
    #[Exclusive]
    #[Schedule('*/5 * * * *')]
    public static function send_pending_queue(Task_Instance $task, array $params = []): array
    {
        $held_back = 0;

        // Drain one at a time until the queue is empty. mark_held_back() moves each
        // row out of PENDING, so get_pending() terminates the loop.
        while (true) {
            $queued = Email_Queue_Model::get_pending(1)->first();
            if (!$queued) {
                break;
            }

            try {
                $queued->rendered_html = static::_render_template($queued);
                $queued->mark_held_back('Delivery not implemented - recorded for developer review (held back in dev).');
                $task->info("Held back email #{$queued->id} to {$queued->to_address}: {$queued->subject}");
            } catch (\Throwable $e) {
                // Never leave a row PENDING (that would loop forever). Record the
                // render failure as held-back with the error for developer review.
                $queued->mark_held_back('Render failed: ' . $e->getMessage());
                $task->error("Render failed for email #{$queued->id}: " . $e->getMessage());
            }

            $held_back++;
        }

        return ['held_back' => $held_back];
    }

    /**
     * Delete old terminal (sent/failed/blocked/held-back) email records.
     */
    #[Task('Clean up old email queue records')]
    #[Schedule('0 3 * * *')]
    public static function cleanup(Task_Instance $task, array $params = []): array
    {
        $days = $params['days'] ?? 90;
        $deleted = Email_Queue_Model::cleanup_old($days);
        $task->info("Deleted {$deleted} email records older than {$days} days");

        return ['deleted' => $deleted];
    }

    /**
     * Render a queued email's blade template to HTML (for storage / recovery).
     * Auto-injects the unsubscribe URL for non-transactional categories.
     */
    private static function _render_template(Email_Queue_Model $queued): string
    {
        $data = $queued->template_data ?? [];

        if ($queued->category_id !== Email_Queue_Model::CATEGORY_TRANSACTIONAL) {
            $original_email = $queued->dev_original_to ?: $queued->to_address;
            $data['unsubscribe_url'] = Rsx_Mail::unsubscribe_url($original_email, $queued->category_id);
        } else {
            $data['unsubscribe_url'] = null;
        }

        $data['subject'] = $queued->subject;

        return rsx_view($queued->template, $data)->render();
    }
}
