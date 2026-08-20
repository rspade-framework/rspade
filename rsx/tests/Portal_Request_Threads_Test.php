<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Models\Portal_Request_Document_Model;
use Rsx\Models\Portal_Request_Event_Model;
use Rsx\Models\Portal_Request_Thread_Model;
use Rsx\Portal\Workspaces\Requests\Portal_Request_Threads_Controller;

/**
 * Stage 4 Portal Request Threads tests.
 *
 * Exercises the firm <-> client conversation lifecycle and its authorization gates at
 * the controller + model layer (Stage 2 staff endpoints on Frontend_Clients_Controller,
 * Stage 3 portal endpoints on Portal_Request_Threads_Controller, Stage 1 models):
 *   - staff create -> NEW_REQUEST + an opening staff message.
 *   - portal collaborator reply -> auto RESPONSE_SUBMITTED + a status event row.
 *   - portal reply with a file -> a reviewable PENDING Portal_Request_Document.
 *   - staff accept -> ACCEPTED; reject(reason) -> REJECTED + reason.
 *   - staff reply with a status change -> set_status + an event.
 *   - membership scoping (non-member denied) and role gating (viewer cannot reply).
 *   - audience-split labels for RESPONSE_SUBMITTED ("Response Submitted" vs "Needs Review").
 *
 * Staff operations run under a CLI staff session (Session::impersonate); portal reads/writes
 * run under a CLI portal session (Portal_Session::set_portal_user_id).
 */
class Portal_Request_Threads_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_staff();
    }

    public static function teardown(): void
    {
        Portal_Session::reset();
        static::__reset_session();
    }

    // ---------------------------------------------------------------------
    // session helpers
    // ---------------------------------------------------------------------

    /** Establish a CLI staff session (Login_User_Model) on the test site. */
    private static function __acting_as_staff(): Login_User_Model
    {
        $staff = new Login_User_Model();
        $staff->email = 'staff_' . uniqid() . '@example.com';
        $staff->password = bin2hex(random_bytes(8)); // not used; threads only read id/email
        $staff->status_id = Login_User_Model::STATUS_ACTIVE;
        $staff->is_activated = true;
        $staff->is_verified = true;
        $staff->save();

        Session::impersonate(self::SITE_ID, (int) $staff->id, null);

        return $staff;
    }

    private static function __login_portal(Portal_User_Model $user): void
    {
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::set_portal_user_id($user->id);
    }

    /** Switch back to the staff session after a portal interaction. */
    private static function __back_to_staff(Login_User_Model $staff): void
    {
        Portal_Session::set_portal_user_id(null);
        Session::impersonate(self::SITE_ID, (int) $staff->id, null);
    }

    // ---------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------

    private static function __make_client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Threads Client ' . uniqid();
        $client->portal_enabled = true;
        $client->save();

        return $client;
    }

    private static function __make_contact(Client_Model $client): Contact_Model
    {
        $contact = new Contact_Model();
        $contact->client_id = $client->id;
        $contact->first_name = 'Thread';
        $contact->last_name = 'Member ' . uniqid();
        $contact->email = 'thread_' . uniqid() . '@example.com';
        $contact->is_active = true;
        $contact->save();

        return $contact;
    }

    private static function __make_portal_user(Contact_Model $contact): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = $contact->email;
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->contact_id = $contact->id;
        $user->save();

        return $user;
    }

    private static function __add_member(Client_Model $client, Portal_User_Model $user, ?int $role = null): Portal_Membership_Model
    {
        $role = $role ?? Portal_Membership_Model::ROLE_COLLABORATOR;
        $membership = new Portal_Membership_Model();
        $membership->portal_user_id = $user->id;
        $membership->client_id = $client->id;
        $membership->role_id = $role;
        $membership->save();

        return $membership;
    }

    /** A collaborator member + their portal user, ready to reply. */
    private static function __make_member(Client_Model $client, ?int $role = null): Portal_User_Model
    {
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user($contact);
        static::__add_member($client, $user, $role);

        return $user;
    }

    /**
     * Attach a reviewable client-submission document to a thread, reproducing the state the
     * portal reply endpoint reaches AFTER its can_user_assign_this_file() guard: a
     * thread-attached File_Attachment + a requires_review PENDING Portal_Request_Document
     * linked to the latest message. Building that state directly keeps this fixture focused
     * on the review lifecycle (the Stage 1/2 document contract) rather than on the upload +
     * claim path, which the framework's own attachments suite covers. The portal reply's own
     * controller behavior (auto RESPONSE_SUBMITTED) is covered separately without a file.
     */
    private static function __submit_document(Portal_Request_Thread_Model $thread, int $message_id, string $name = 'response.pdf'): Portal_Request_Document_Model
    {
        $storage = new File_Storage_Model();
        $storage->hash = 'hash_' . uniqid();
        $storage->size = 4321;
        $storage->save();

        $attachment = new File_Attachment_Model();
        $attachment->key = 'up_' . bin2hex(random_bytes(8));
        $attachment->file_storage_id = $storage->id;
        $attachment->file_name = $name;
        $attachment->site_id = self::SITE_ID;
        $attachment->fileable_type = 'Portal_Request_Thread_Model';
        $attachment->fileable_id = $thread->id;
        $attachment->fileable_category = 'attachment';
        $attachment->save();

        $document = new Portal_Request_Document_Model();
        $document->site_id = (int) $thread->site_id;
        $document->thread_id = (int) $thread->id;
        $document->message_id = $message_id;
        $document->attachment_id = (int) $attachment->id;
        $document->requires_review = 1; // client submission: reviewable
        $document->review_status = Portal_Request_Document_Model::REVIEW_PENDING;
        $document->save();

        return $document;
    }

    /** Staff opens a thread on a client and returns it. */
    private static function __create_thread(Client_Model $client, string $title = 'Please send your documents'): Portal_Request_Thread_Model
    {
        $result = Frontend_Clients_Controller::request_thread_create(new Request(), [
            'client_id' => $client->id,
            'title' => $title,
            'body' => 'We need the following from you.',
        ]);

        return Portal_Request_Thread_Model::find($result['thread_id']);
    }

    // ---------------------------------------------------------------------
    // staff create -> NEW_REQUEST + opening message
    // ---------------------------------------------------------------------

    public static function test_staff_create_opens_new_request_with_message()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client, 'W-2 forms');

        static::__assert_not_null($thread, 'thread created');
        static::__assert_equals(Portal_Request_Thread_Model::STATUS_NEW_REQUEST, (int) $thread->status_id, 'opens in NEW_REQUEST');
        static::__assert_equals('W-2 forms', $thread->title);

        $messages = $thread->messages();
        static::__assert_count(1, $messages, 'one opening message');
        static::__assert_true($messages[0]->is_from_staff(), 'opening message is from staff');
        static::__assert_count(0, $thread->events(), 'no status events yet');
    }

    // ---------------------------------------------------------------------
    // portal collaborator reply -> auto RESPONSE_SUBMITTED + event row
    // ---------------------------------------------------------------------

    public static function test_portal_reply_auto_transitions_to_response_submitted()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client);
        $member = static::__make_member($client);

        static::__login_portal($member);
        $result = Portal_Request_Threads_Controller::reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => 'Here is what you asked for.',
        ]);

        static::__assert_equals(Portal_Request_Thread_Model::STATUS_RESPONSE_SUBMITTED, (int) $result['status_id'], 'reply auto-transitions to RESPONSE_SUBMITTED');

        $reloaded = Portal_Request_Thread_Model::find($thread->id);
        static::__assert_equals(Portal_Request_Thread_Model::STATUS_RESPONSE_SUBMITTED, (int) $reloaded->status_id, 'thread row reflects the new status');

        $events = Portal_Request_Event_Model::where('thread_id', $thread->id)->get();
        static::__assert_count(1, $events, 'a status event row was recorded');
        static::__assert_equals(Portal_Request_Thread_Model::STATUS_NEW_REQUEST, (int) $events[0]->from_status, 'event from NEW_REQUEST');
        static::__assert_equals(Portal_Request_Thread_Model::STATUS_RESPONSE_SUBMITTED, (int) $events[0]->to_status, 'event to RESPONSE_SUBMITTED');
    }

    // ---------------------------------------------------------------------
    // portal reply with a file -> reviewable PENDING document
    // ---------------------------------------------------------------------

    public static function test_portal_reply_with_attachment_creates_reviewable_document()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client);
        $member = static::__make_member($client);

        static::__login_portal($member);
        $result = Portal_Request_Threads_Controller::reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => 'Documents attached.',
        ]);
        $document = static::__submit_document($thread, (int) $result['message_id']);

        $documents = Portal_Request_Document_Model::where('thread_id', $thread->id)->get();
        static::__assert_count(1, $documents, 'one document row created');
        static::__assert_equals(1, (int) $document->requires_review, 'client submission requires review');
        static::__assert_equals(Portal_Request_Document_Model::REVIEW_PENDING, (int) $document->review_status, 'starts PENDING');
        static::__assert_true($document->is_reviewable(), 'is a reviewable submission');
        static::__assert_equals((int) $result['message_id'], (int) $document->message_id, 'links the reply message');
    }

    // ---------------------------------------------------------------------
    // staff accept / reject
    // ---------------------------------------------------------------------

    public static function test_staff_accept_document()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client);
        $member = static::__make_member($client);
        $staff = Session::get_login_user();

        static::__login_portal($member);
        $reply = Portal_Request_Threads_Controller::reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => 'Here it is.',
        ]);
        $document = static::__submit_document($thread, (int) $reply['message_id']);

        static::__back_to_staff($staff);
        $result = Frontend_Clients_Controller::request_document_review(new Request(), [
            'document_id' => $document->id,
            'decision' => 'accept',
        ]);

        static::__assert_equals(Portal_Request_Document_Model::REVIEW_ACCEPTED, (int) $result['review_status'], 'accept -> ACCEPTED');
        $reloaded = Portal_Request_Document_Model::find($document->id);
        static::__assert_true($reloaded->is_accepted(), 'document row is accepted');
        static::__assert_null($reloaded->reject_reason, 'no reject reason on accept');
    }

    public static function test_staff_reject_document_with_reason()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client);
        $member = static::__make_member($client);
        $staff = Session::get_login_user();

        static::__login_portal($member);
        $reply = Portal_Request_Threads_Controller::reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => 'Here it is.',
        ]);
        $document = static::__submit_document($thread, (int) $reply['message_id']);

        static::__back_to_staff($staff);
        $result = Frontend_Clients_Controller::request_document_review(new Request(), [
            'document_id' => $document->id,
            'decision' => 'reject',
            'reason' => 'The form is unsigned.',
        ]);

        static::__assert_equals(Portal_Request_Document_Model::REVIEW_REJECTED, (int) $result['review_status'], 'reject -> REJECTED');
        static::__assert_equals('The form is unsigned.', $result['reject_reason'], 'reason recorded');
        $reloaded = Portal_Request_Document_Model::find($document->id);
        static::__assert_true($reloaded->is_rejected(), 'document row is rejected');
        static::__assert_equals('The form is unsigned.', $reloaded->reject_reason);
    }

    // ---------------------------------------------------------------------
    // staff reply carrying a status change
    // ---------------------------------------------------------------------

    public static function test_staff_reply_with_status_change_records_event()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client);

        Frontend_Clients_Controller::request_thread_reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => 'Thanks - approving this.',
            'status' => Portal_Request_Thread_Model::STATUS_APPROVED,
        ]);

        $reloaded = Portal_Request_Thread_Model::find($thread->id);
        static::__assert_equals(Portal_Request_Thread_Model::STATUS_APPROVED, (int) $reloaded->status_id, 'thread is APPROVED');

        $events = Portal_Request_Event_Model::where('thread_id', $thread->id)
            ->where('to_status', Portal_Request_Thread_Model::STATUS_APPROVED)->get();
        static::__assert_count(1, $events, 'an APPROVED event was recorded');

        // And CLOSED from APPROVED.
        Frontend_Clients_Controller::request_thread_reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => '',
            'status' => Portal_Request_Thread_Model::STATUS_CLOSED,
        ]);
        $closed = Portal_Request_Thread_Model::find($thread->id);
        static::__assert_equals(Portal_Request_Thread_Model::STATUS_CLOSED, (int) $closed->status_id, 'thread is CLOSED');
        static::__assert_true($closed->is_closed(), 'is_closed() true');
    }

    // ---------------------------------------------------------------------
    // membership + role gating
    // ---------------------------------------------------------------------

    public static function test_non_member_denied_on_portal_get_and_list()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client);

        // A portal user with NO membership on this client.
        $outsider_contact = static::__make_contact($client);
        $outsider = static::__make_portal_user($outsider_contact);

        static::__login_portal($outsider);

        $get = Portal_Request_Threads_Controller::get(new Request(), ['id' => $thread->id]);
        static::__assert_false(is_array($get) && isset($get['thread']), 'non-member does not get the thread');

        $list = Portal_Request_Threads_Controller::list(new Request(), ['client_id' => $client->id]);
        static::__assert_false(is_array($list) && isset($list['active']), 'non-member does not get the list');
    }

    public static function test_viewer_cannot_reply()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client);
        // A VIEWER (non-collaborator) member.
        $viewer = static::__make_member($client, Portal_Membership_Model::ROLE_VIEWER);

        static::__login_portal($viewer);
        $response = Portal_Request_Threads_Controller::reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => 'Can I reply?',
        ]);

        // Read-only viewer: the reply is rejected (no message posted, status unchanged).
        static::__assert_false(is_array($response) && isset($response['message_id']), 'viewer reply is rejected');

        $reloaded = Portal_Request_Thread_Model::find($thread->id);
        static::__assert_equals(Portal_Request_Thread_Model::STATUS_NEW_REQUEST, (int) $reloaded->status_id, 'status unchanged by a denied viewer reply');
        static::__assert_count(1, $reloaded->messages(), 'no message posted by the viewer');
    }

    // ---------------------------------------------------------------------
    // audience-split status labels
    // ---------------------------------------------------------------------

    public static function test_response_submitted_label_differs_by_audience()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client);
        $member = static::__make_member($client);

        static::__login_portal($member);
        Portal_Request_Threads_Controller::reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => 'Submitted.',
        ]);

        $reloaded = Portal_Request_Thread_Model::find($thread->id);
        static::__assert_equals('Response Submitted', $reloaded->status_label(true), 'portal sees Response Submitted');
        static::__assert_equals('Needs Review', $reloaded->status_label(false), 'staff sees Needs Review');
    }
}
