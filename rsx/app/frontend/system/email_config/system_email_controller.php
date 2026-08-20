<?php


namespace Rsx\App\Frontend\System\EmailConfig;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Mail\Rsx_Mail;
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

        return [
            'mail_driver' => config('mail.default', 'smtp'),
            'smtp_host' => config('mail.mailers.smtp.host', '-'),
            'smtp_port' => config('mail.mailers.smtp.port', '-'),
            'from_address' => config('rsx.mail.from_address', config('mail.from.address', '-')),
            'from_name' => config('rsx.mail.from_name') ?: config('rsx.name', '-'),
            'is_dev_site' => \App\RSpade\Core\Rsx::is_dev_site(),
            'dev_catchall' => env('EMAIL_DEV_CATCHALL_ADDRESS'),
            'dev_suppressed' => Rsx_Mail::is_delivery_suppressed(),
            'dev_address_whitelist' => env('EMAIL_DEV_EMAIL_ADDRESS_WHITELIST', ''),
            'dev_domain_whitelist' => env('EMAIL_DEV_EMAIL_DOMAIN_WHITELIST', ''),
            'retry_max' => config('rsx.mail.retry_max', 6),
            'stats' => [
                'pending' => $pending,
                'sent' => $sent,
                'failed' => $failed,
                'blocked' => $blocked,
                'total' => $pending + $sent + $failed + $blocked,
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
                'template' => $record->template,
                'category_id' => $record->category_id,
                'category_id__label' => $record->category_id__label,
                'category_id__badge' => $record->category_id__badge,
                'status_id' => $record->status_id,
                'status_id__label' => $record->status_id__label,
                'status_id__badge' => $record->status_id__badge,
                'retry_count' => $record->retry_count,
                'error' => $record->error,
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
     * Get rendered HTML for an email (preview)
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

        // If not yet rendered, try to render now
        $html = $email->rendered_html;
        if (!$html && $email->template) {
            try {
                $data = $email->template_data ?? [];
                $data['subject'] = $email->subject;
                if ($email->category_id !== Email_Queue_Model::CATEGORY_TRANSACTIONAL) {
                    $data['unsubscribe_url'] = Rsx_Mail::unsubscribe_url($email->to_address, $email->category_id);
                }
                $html = rsx_view($email->template, $data)->render();
            } catch (\Exception $e) {
                $html = '<p>Error rendering template: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        }

        return [
            'html' => $html ?: '<p>No rendered content available.</p>',
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
            'template' => $record->template,
            'category_id' => $record->category_id,
            'category_id__label' => $record->category_id__label,
            'category_id__badge' => $record->category_id__badge,
            'status_id' => $record->status_id,
            'status_id__label' => $record->status_id__label,
            'status_id__badge' => $record->status_id__badge,
            'retry_count' => $record->retry_count,
            'error' => $record->error,
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
        $email->retry_count = 0;
        $email->retry_at = null;
        $email->error = null;
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
