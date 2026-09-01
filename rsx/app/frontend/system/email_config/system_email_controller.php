<?php


namespace Rsx\App\Frontend\System\EmailConfig;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Email_Recipient_Model;

/**
 */
#[Auth('is_logged_in')]
class System_Email_Controller extends Rsx_Controller_Abstract
{
    /**
     * Get email system configuration (read-only display)
     */
    #[Ajax_Endpoint]
    public static function get_config(Request $request, array $params = [])
    {
        $pending = Email_Queue_Model::where('status_id', Email_Queue_Model::STATUS_PENDING)->count();
        $sent = Email_Queue_Model::where('status_id', Email_Queue_Model::STATUS_SENT)->count();
        $failed = Email_Queue_Model::where('status_id', Email_Queue_Model::STATUS_FAILED)->count();
        $blocked = Email_Queue_Model::where('status_id', Email_Queue_Model::STATUS_BLOCKED)->count();
        $suppressed = Email_Queue_Model::where('status_id', Email_Queue_Model::STATUS_SUPPRESSED)->count();

        // The master switch, four-valued (aiosmtpd | live | suppressed | disabled).
        // Asked of the transport rather than read from config so an unrecognised value
        // throws here instead of being rendered as "not live".
        $mode = \App\RSpade\Core\Mail\Rsx_Mail_Transport::delivery_mode();

        $delivery_display = [
            'aiosmtpd' => ['Development catcher', 'bg-info', 'Captured on this box at 127.0.0.1:1025 and delivered to nobody'],
            'live' => ['Live', 'bg-success', 'Messages are handed to the transport'],
            'suppressed' => ['Suppressed', 'bg-dark', 'Messages render and are recorded, never sent'],
            'disabled' => ['Disabled', 'bg-secondary', 'The queue is frozen - messages stay pending until delivery is re-enabled'],
        ][$mode];

        return [
            'delivery' => $mode,
            'delivery_label' => $delivery_display[0],
            'delivery_badge' => $delivery_display[1],
            'delivery_description' => $delivery_display[2],
            // What the transport ACTUALLY is: in aiosmtpd mode the rsx.mail.transport
            // block is ignored entirely, so reading it here would print a lie.
            'transport_driver' => $mode === 'aiosmtpd' ? 'smtp' : config('rsx.mail.transport.driver'),
            'transport_target' => \App\RSpade\Core\Mail\Rsx_Mail_Transport::describe(),
            'from_address' => config('rsx.mail.from_address'),
            'from_name' => config('rsx.mail.from_name') ?: config('rsx.name', '-'),
            'is_dev_site' => \App\RSpade\Core\Rsx::is_dev_site(),
            'dev_catchall' => config('rsx.mail.dev_site.catchall_address'),
            'dev_address_whitelist' => config('rsx.mail.dev_site.address_whitelist', ''),
            'dev_domain_whitelist' => config('rsx.mail.dev_site.domain_whitelist', ''),
            'retry_attempts' => config('rsx.mail.retry.attempts'),
            'retry_delay_minutes' => config('rsx.mail.retry.delay_minutes'),
            'retention_days' => config('rsx.mail.retention_days'),
            'stale_after_days' => config('rsx.mail.stale_after_days'),
            'stats' => [
                'pending' => $pending,
                'sent' => $sent,
                'failed' => $failed,
                'blocked' => $blocked,
                'suppressed' => $suppressed,
                'total' => $pending + $sent + $failed + $blocked + $suppressed,
            ],
        ];
    }

    /**
     * Get email queue records (for DataGrid)
     */
    #[Ajax_Endpoint]
    public static function queue_fetch(Request $request, array $params = [])
    {
        $page = $params['page'] ?? 1;
        $per_page = $params['per_page'] ?? 20;
        $status = $params['status'] ?? null;
        $search = $params['search'] ?? null;

        $query = Email_Queue_Model::query()->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status_id', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('to_address', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhere('dev_original_to', 'like', "%{$search}%");
            });
        }

        $total = $query->count();
        $records = $query->skip(($page - 1) * $per_page)->take($per_page)->get();

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => $record->id,
                'to_address' => $record->to_address,
                'dev_original_to' => $record->dev_original_to,
                'subject' => $record->subject,
                'email_class' => $record->email_class,
                'category_id' => $record->category_id,
                'category_id__label' => $record->category_id__label,
                'category_id__badge' => $record->category_id__badge,
                'status_id' => $record->status_id,
                'status_id__label' => $record->status_id__label,
                'status_id__badge' => $record->status_id__badge,
                'attempt_count' => $record->attempt_count,
                'last_error' => $record->last_error,
                'next_attempt_at' => $record->next_attempt_at,
                'sent_at' => $record->sent_at,
                'created_at' => $record->created_at,
            ];
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * The stored rendered body of one queued email.
     *
     * It shows what was (or will be) SENT, so it reads the row and renders nothing:
     * re-rendering here would show today's template with today's config against
     * yesterday's data, which is exactly the email nobody sent. A row that has not
     * been through the builder yet says so.
     */
    #[Ajax_Endpoint]
    public static function queue_preview(Request $request, array $params = [])
    {
        $id = $params['id'] ?? null;
        if (!$id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Email ID is required');
        }

        $email = Email_Queue_Model::find($id);
        if (!$email) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Email not found');
        }

        return [
            'html' => $email->rendered_html,
            'is_rendered' => $email->rendered_html !== null && $email->rendered_html !== '',
            'subject' => $email->subject,
            'to_address' => $email->to_address,
            'dev_original_to' => $email->dev_original_to,
        ];
    }

    /**
     * Get a single email record by ID
     */
    #[Ajax_Endpoint]
    public static function queue_get(Request $request, array $params = [])
    {
        $id = $params['id'] ?? null;
        if (!$id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Email ID is required');
        }

        $record = Email_Queue_Model::find($id);
        if (!$record) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Email not found');
        }

        return [
            'id' => $record->id,
            'to_address' => $record->to_address,
            'to_name' => $record->to_name,
            'dev_original_to' => $record->dev_original_to,
            'subject' => $record->subject,
            'email_class' => $record->email_class,
            'category_id' => $record->category_id,
            'category_id__label' => $record->category_id__label,
            'category_id__badge' => $record->category_id__badge,
            'status_id' => $record->status_id,
            'status_id__label' => $record->status_id__label,
            'status_id__badge' => $record->status_id__badge,
            'attempt_count' => $record->attempt_count,
            'last_error' => $record->last_error,
            'next_attempt_at' => $record->next_attempt_at,
            'sent_at' => $record->sent_at,
            'created_at' => $record->created_at,
        ];
    }

    /**
     * Resend a failed email
     */
    #[Ajax_Endpoint]
    public static function queue_resend(Request $request, array $params = [])
    {
        $id = $params['id'] ?? null;
        if (!$id) {
            return response_error(Ajax::ERROR_VALIDATION, 'Email ID is required');
        }

        $email = Email_Queue_Model::find($id);
        if (!$email) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Email not found');
        }

        $email->status_id = Email_Queue_Model::STATUS_PENDING;
        $email->attempt_count = 0;
        $email->next_attempt_at = null;
        $email->last_error = null;
        $email->save();

        return ['message' => 'Email re-queued for delivery'];
    }

    /**
     * Get email recipients (for DataGrid)
     */
    #[Ajax_Endpoint]
    public static function recipients_fetch(Request $request, array $params = [])
    {
        $page = $params['page'] ?? 1;
        $per_page = $params['per_page'] ?? 20;
        $search = $params['search'] ?? null;

        $query = Email_Recipient_Model::query()->orderBy('email', 'asc');

        if ($search) {
            $query->where('email', 'like', "%{$search}%");
        }

        $total = $query->count();
        $records = $query->skip(($page - 1) * $per_page)->take($per_page)->get();

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => $record->id,
                'email' => $record->email,
                'is_blocked_notification' => $record->is_blocked_notification,
                'is_blocked_marketing' => $record->is_blocked_marketing,
                'is_blocked_all' => $record->is_blocked_all,
                'total_sent' => $record->total_sent,
                'total_failed' => $record->total_failed,
                'last_sent_at' => $record->last_sent_at,
                'unsubscribed_at' => $record->unsubscribed_at,
            ];
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Toggle blocklist status for a recipient
     */
    #[Ajax_Endpoint]
    public static function recipients_toggle_block(Request $request, array $params = [])
    {
        $id = $params['id'] ?? null;
        $field = $params['field'] ?? null;

        if (!$id || !$field) {
            return response_error(Ajax::ERROR_VALIDATION, 'Recipient ID and field are required');
        }

        $recipient = Email_Recipient_Model::find($id);
        if (!$recipient) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Recipient not found');
        }

        $allowed_fields = ['is_blocked_notification', 'is_blocked_marketing', 'is_blocked_all'];
        if (!in_array($field, $allowed_fields)) {
            return response_error(Ajax::ERROR_VALIDATION, 'Invalid field');
        }

        $recipient->$field = !$recipient->$field;
        if ($recipient->$field && !$recipient->unsubscribed_at) {
            $recipient->unsubscribed_at = now();
        }
        $recipient->save();

        return ['value' => $recipient->$field];
    }
}
