<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Workspaces\Requests;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Rsx;
use Rsx\Emails\Portal_Request_Reply_Email;
use Rsx\Models\Client_Model;
use Rsx\Models\Portal_Request_Document_Model;
use Rsx\Models\Portal_Request_Thread_Model;
use Rsx\Portal_Permission;

/**
 * Portal_Request_Threads_Controller - the portal-authed surface for request threads
 * (firm <-> client conversations). The portal mirror of the staff thread endpoints on
 * Frontend_Clients_Controller (request_threads_list / request_thread_get /
 * request_thread_reply) MINUS the staff-only actions: a portal user can read the thread
 * and post a reply (which auto-transitions the thread to RESPONSE_SUBMITTED), but cannot
 * accept/reject documents or set arbitrary statuses.
 *
 * The thread is CLIENT-LEVEL: every member of the thread's client sees it and (collaborators)
 * reply. Audience labels are the portal/canonical labels (status_label(true)).
 *
 * Authorization: the class-level #[Auth('is_logged_in')] gate (portal realm) admits
 * only a logged-in portal user. Every endpoint additionally fails closed for a client the caller is
 * not a member of via Portal_Permission::has_client_access(); reply additionally requires
 * Portal_Permission::can_collaborate() (viewers are read-only). The client is always
 * derived from the thread row + the membership gate, never trusted blindly. No
 * notifications here yet (Stage 4).
 */
#[Auth('is_logged_in')]
class Portal_Request_Threads_Controller extends Rsx_Controller_Abstract
{
    /**
     * Category under which a thread's document File_Attachments are attached (fileable =
     * the thread). Matches the staff side (Frontend_Clients_Controller::DOCUMENTS_THREAD_CATEGORY).
     */
    const DOCUMENTS_THREAD_CATEGORY = 'attachment';

    /**
     * Ajax endpoint: request threads for a client (membership-gated), split into active
     * (status != CLOSED) and closed groups. Each row carries the list essentials with the
     * PORTAL status label.
     */
    #[Ajax_Endpoint]
    public static function list(Request $request, array $params = [])
    {
        $client_id = isset($params['client_id']) ? (int) $params['client_id'] : 0;
        if ($client_id <= 0) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        if (!Portal_Permission::has_client_access($client_id)) {
            return response_unauthorized();
        }

        $active = [];
        $closed = [];

        foreach (Portal_Request_Thread_Model::for_client($client_id) as $thread) {
            $row = [
                'id' => $thread->id,
                'title' => $thread->title,
                'status_id' => $thread->status_id,
                'status_label' => $thread->status_label(true),
                'badge' => $thread->status_id__badge,
                'last_activity_at' => $thread->last_activity_at ?: $thread->created_at,
                'message_count' => count($thread->messages()),
            ];

            if ((int) $thread->status_id === Portal_Request_Thread_Model::STATUS_CLOSED) {
                $closed[] = $row;
            } else {
                $active[] = $row;
            }
        }

        return [
            'active' => $active,
            'closed' => $closed,
        ];
    }

    /**
     * Ajax endpoint: the full thread for the portal thread view - vitals, a merged timeline
     * (messages + status events ordered by created_at), participants (active members of the
     * client), and the sidebar document buckets (Awaiting Review = reviewable+pending,
     * Accepted). Membership-gated by the THREAD's client_id. Portal labels throughout; no
     * staff-only actions are exposed.
     */
    #[Ajax_Endpoint]
    public static function get(Request $request, array $params = [])
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return response_error(Ajax::ERROR_VALIDATION, 'Thread ID is required');
        }

        $thread = Portal_Request_Thread_Model::find($id);
        if (!$thread || !Portal_Permission::has_client_access((int) $thread->client_id)) {
            return response_not_found('Request not found');
        }

        $client = Client_Model::find($thread->client_id);

        // Index every review document by message_id so messages carry their chips, and
        // build the sidebar buckets in one pass.
        $docs_by_message = [];
        $needs_review = [];
        $accepted = [];
        foreach ($thread->documents() as $document) {
            $doc_row = static::_thread_document_row($document);
            if ($doc_row === null) {
                continue;
            }
            $docs_by_message[(int) $document->message_id][] = $doc_row;

            if ($document->is_reviewable() && $document->is_pending()) {
                $needs_review[] = $doc_row;
            } elseif ($document->is_accepted()) {
                $accepted[] = $doc_row;
            }
        }

        // Merge messages + events into one timeline ordered by created_at (then id).
        $timeline = [];
        foreach ($thread->messages() as $message) {
            $timeline[] = [
                'sort_key' => $message->created_at . sprintf('-1-%010d', (int) $message->id),
                'entry' => [
                    'type' => 'message',
                    'id' => $message->id,
                    'author_name' => $message->author_name(),
                    'author_email' => $message->author_email(),
                    'is_from_staff' => $message->is_from_staff(),
                    'body' => $message->body,
                    'created_at' => $message->created_at,
                    'documents' => $docs_by_message[(int) $message->id] ?? [],
                ],
            ];
        }
        foreach ($thread->events() as $event) {
            $timeline[] = [
                'sort_key' => $event->created_at . sprintf('-2-%010d', (int) $event->id),
                'entry' => [
                    'type' => 'event',
                    'id' => $event->id,
                    'from_label' => $event->from_status_label(true),
                    'to_label' => $event->to_status_label(true),
                    'actor_name' => $event->actor_name(),
                    'created_at' => $event->created_at,
                ],
            ];
        }
        usort($timeline, fn ($a, $b) => strcmp($a['sort_key'], $b['sort_key']));
        $timeline = array_map(fn ($t) => $t['entry'], $timeline);

        // Participants = client members (every portal member of the client) + staff who
        // have posted in this thread. Two labelled groups, deduped within each.
        $participants = $thread->participants();

        return [
            'thread' => [
                'id' => $thread->id,
                'title' => $thread->title,
                'client_id' => $thread->client_id,
                'status_id' => $thread->status_id,
                'status_label' => $thread->status_label(true),
                'badge' => $thread->status_id__badge,
                'is_closed' => $thread->is_closed(),
                'can_collaborate' => Portal_Permission::can_collaborate((int) $thread->client_id),
            ],
            'timeline' => $timeline,
            'participants' => $participants,
            'needs_review' => $needs_review,
            'accepted' => $accepted,
        ];
    }

    /**
     * Ajax endpoint: the client posts a reply to a thread (membership-gated by the thread's
     * client). Collaborators only (viewers are read-only -> unauthorized). The message
     * auto-transitions a non-closed thread to RESPONSE_SUBMITTED (model contract). Any
     * attached files become reviewable client submissions (Portal_Request_Document_Model
     * with requires_review=1, review_status PENDING). The body may be empty only when at
     * least one file is attached. No notifications yet (Stage 4).
     */
    #[Ajax_Endpoint]
    public static function reply(Request $request, array $params = [])
    {
        if (Portal_Permission::is_read_only()) {
            return response_unauthorized('This is a read-only session; changes are disabled.');
        }

        $thread_id = isset($params['thread_id']) ? (int) $params['thread_id'] : 0;
        $body = trim($params['body'] ?? '');
        $attachment_keys = $params['attachment_keys'] ?? [];

        if ($thread_id <= 0) {
            return response_error(Ajax::ERROR_VALIDATION, 'Thread ID is required');
        }
        if (!is_array($attachment_keys)) {
            $attachment_keys = [];
        }

        $thread = Portal_Request_Thread_Model::find($thread_id);
        if (!$thread || !Portal_Permission::has_client_access((int) $thread->client_id)) {
            return response_not_found('Request not found');
        }

        // Viewers are read-only: only collaborators may post.
        if (!Portal_Permission::can_collaborate((int) $thread->client_id)) {
            return response_unauthorized('You do not have permission to reply to this request.');
        }

        if ($thread->is_closed()) {
            return response_error(Ajax::ERROR_VALIDATION, 'This request is closed.');
        }

        if ($body === '' && empty($attachment_keys)) {
            return response_form_error('Validation failed', [
                'body' => 'Enter a message or attach a file',
            ]);
        }

        $portal_user = Portal_Permission::current_user();

        // Post the message (portal author -> auto RESPONSE_SUBMITTED on a non-closed thread).
        $message = $thread->post_message($portal_user, $body);

        // Attach client files (reviewable submissions). Validate assignability first.
        foreach ($attachment_keys as $key) {
            $attachment = File_Attachment_Model::find_by_key($key);
            if (!$attachment || !$attachment->can_user_assign_this_file()) {
                continue;
            }
            $attachment->add_to($thread, static::DOCUMENTS_THREAD_CATEGORY);

            $document = new Portal_Request_Document_Model();
            $document->site_id = (int) $thread->site_id;
            $document->thread_id = (int) $thread->id;
            $document->message_id = (int) $message->id;
            $document->attachment_id = (int) $attachment->id;
            $document->requires_review = 1; // client submission: reviewable
            $document->review_status = Portal_Request_Document_Model::REVIEW_PENDING;
            $document->save();
        }

        // Email the staff thread creator that the client responded. No in-app staff
        // notification system exists, so email is the channel. Best-effort, non-fatal:
        // an email failure must never break the client's reply.
        try {
            static::_email_staff_of_reply($thread, $portal_user, $body);
        } catch (\Throwable $e) {
            // swallow - the reply itself succeeded
        }

        return [
            'thread_id' => $thread->id,
            'message_id' => $message->id,
            'status_id' => $thread->status_id,
        ];
    }

    /**
     * Email the thread's creator (the staff member who opened it) that the client posted a
     * reply. The creator comes from the framework audit authorship pair, so it is whichever
     * identity actually created the thread; guards for an unattributed thread or a
     * deleted/email-less staff user. Transactional category so it always delivers.
     *
     * @param Portal_Request_Thread_Model $thread
     * @param Portal_User_Model $portal_user The replying client user.
     * @param string $body The client's message body (may be empty for attach-only).
     * @return void
     */
    private static function _email_staff_of_reply(Portal_Request_Thread_Model $thread, Portal_User_Model $portal_user, string $body): void
    {
        $staff = $thread->created_by;
        if (!$staff || empty($staff->email)) {
            return;
        }

        $client = Client_Model::find($thread->client_id);

        $snippet = trim($body);
        if (mb_strlen($snippet) > 200) {
            $snippet = mb_substr($snippet, 0, 200) . '...';
        }
        if ($snippet === '') {
            $snippet = '(no message text - see the request for attached files)';
        }

        $view_url = rsx_absolute_url(Rsx::Route('Clients_Request_Thread_Action', [
            'id' => (int) $thread->client_id,
            'thread_id' => (int) $thread->id,
        ]));

        (new Portal_Request_Reply_Email(
            trim((string) ($staff->name ?? '')) ?: $staff->email,
            $client ? $client->name : 'A client',
            $portal_user->email,
            $thread->title,
            $snippet,
            $view_url
        ))->to($staff->email)->send();
    }

    /**
     * Ajax endpoint: threads across ALL the caller's accessible clients whose status is
     * NEW_REQUEST or NEEDS_MORE_INFO (the firm is waiting on the client's response). For
     * the portal dashboard "Action needed" panel. Newest activity first.
     */
    #[Ajax_Endpoint]
    public static function needs_response_for_user(Request $request, array $params = [])
    {
        $client_ids = Portal_Permission::accessible_client_ids();
        if (empty($client_ids)) {
            return ['threads' => []];
        }

        $rows = Portal_Request_Thread_Model::whereIn('client_id', $client_ids)
            ->whereIn('status_id', [
                Portal_Request_Thread_Model::STATUS_NEW_REQUEST,
                Portal_Request_Thread_Model::STATUS_NEEDS_MORE_INFO,
            ])
            ->orderByRaw('COALESCE(last_activity_at, created_at) DESC')
            ->orderBy('id', 'desc')
            ->get();

        $client_names = [];
        $threads = [];
        foreach ($rows as $thread) {
            $cid = (int) $thread->client_id;
            if (!isset($client_names[$cid])) {
                $client = Client_Model::find($cid);
                $client_names[$cid] = $client ? $client->name : 'Workspace';
            }

            $threads[] = [
                'id' => $thread->id,
                'client_id' => $cid,
                'client_name' => $client_names[$cid],
                'title' => $thread->title,
                'status_label' => $thread->status_label(true),
                'badge' => $thread->status_id__badge,
            ];
        }

        return ['threads' => $threads];
    }

    /**
     * Build the JS-facing row for one thread document (file metadata + review state), or
     * null if the underlying File_Attachment is missing. Mirrors the staff row shape so the
     * portal timeline/sidebar/modal reuse the same fields.
     *
     * @param Portal_Request_Document_Model $document
     * @return array|null
     */
    private static function _thread_document_row(Portal_Request_Document_Model $document): ?array
    {
        $attachment = $document->attachment();
        if (!$attachment) {
            return null;
        }

        return [
            'id' => $document->id,
            'message_id' => $document->message_id,
            'name' => $attachment->file_name,
            'attachment_id' => (int) $attachment->id,
            'download_url' => $attachment->get_download_url(),
            'requires_review' => (int) $document->requires_review,
            'review_status' => $document->review_status,
            'review_label' => $document->review_status__label,
            'badge' => $document->review_status__badge,
            'reject_reason' => $document->reject_reason,
        ];
    }
}
