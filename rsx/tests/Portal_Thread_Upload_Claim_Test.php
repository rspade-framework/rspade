<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Rsx_File_Gates;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Models\Portal_Request_Document_Model;
use Rsx\Models\Portal_Request_Thread_Model;
use Rsx\Portal\Workspaces\Requests\Portal_Request_Threads_Controller;

/**
 * A portal client's file, end to end, through the real code at every step: uploaded through
 * the shared `/_upload` endpoint in the PORTAL realm (gated by `file.upload.authorize`),
 * claimed onto a request thread by the portal reply endpoint (`find_by_key()` +
 * `can_user_assign_this_file()` + `add_to()`), and then readable through the file read gates
 * by a member of that client and by nobody from another client.
 *
 * Portal_Request_Threads_Test builds the attached state by hand to test the review
 * lifecycle; this is the path that state is supposed to come from.
 */
class Portal_Thread_Upload_Claim_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_staff();
    }

    public static function teardown(): void
    {
        Rsx_Request_Channel::reset();
        static::__reset_session();
    }

    // ---------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------

    private static function __acting_as_staff(): void
    {
        $staff = new Login_User_Model();
        $staff->email = 'staff_' . uniqid() . '@example.com';
        $staff->password = bin2hex(random_bytes(8));
        $staff->status_id = Login_User_Model::STATUS_ACTIVE;
        $staff->is_activated = true;
        $staff->is_verified = true;
        $staff->save();

        Portal_Session::set_portal_user_id(null);
        Rsx_Portal::set_portal_request(false);
        Session::impersonate(self::SITE_ID, (int) $staff->id, null);
    }

    /** Sign a portal user in, on a request in the PORTAL realm. */
    private static function __as_portal(Portal_User_Model $user): void
    {
        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::set_portal_user_id($user->id);
    }

    private static function __make_client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Upload Client ' . uniqid();
        $client->portal_enabled = true;
        $client->save();

        return $client;
    }

    /** A collaborator member of $client, with the contact and portal user behind it. */
    private static function __make_member(Client_Model $client): Portal_User_Model
    {
        $contact = new Contact_Model();
        $contact->client_id = $client->id;
        $contact->first_name = 'Upload';
        $contact->last_name = 'Member ' . uniqid();
        $contact->email = 'upload_' . uniqid() . '@example.com';
        $contact->is_active = true;
        $contact->save();

        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = $contact->email;
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->contact_id = $contact->id;
        $user->save();

        $membership = new Portal_Membership_Model();
        $membership->portal_user_id = $user->id;
        $membership->client_id = $client->id;
        $membership->role_id = Portal_Membership_Model::ROLE_COLLABORATOR;
        $membership->save();

        return $user;
    }

    private static function __create_thread(Client_Model $client): Portal_Request_Thread_Model
    {
        $result = Frontend_Clients_Controller::request_thread_create(new Request(), [
            'client_id' => $client->id,
            'title' => 'Please send your statement',
            'body' => 'We need last month\'s statement.',
        ]);

        return Portal_Request_Thread_Model::find($result['thread_id']);
    }

    /** POST /_upload with a real file, as the signed-in portal user; the response's attachment. */
    private static function __upload(string $name, string $bytes): array
    {
        $tmp = Rsx_Project_Paths::tmp_path('portal_upload_' . random_hash(8) . '_' . $name);
        file_put_contents($tmp, $bytes);
        $file = new UploadedFile($tmp, $name, null, null, true);

        $response = File_Attachment_Controller::upload(Request::create('/_upload', 'POST', [], [], ['file' => $file]));
        $payload = $response->getData(true);

        static::__assert_true(!empty($payload['success']), 'the portal upload was accepted: ' . json_encode($payload));

        return $payload['attachment'];
    }

    /** Whether the file read gates let $user (portal realm) read $attachment. */
    private static function __portal_user_may_read(File_Attachment_Model $attachment, Portal_User_Model $user): bool
    {
        static::__as_portal($user);
        $payload = ['attachment' => $attachment, 'user' => $user, 'request' => Request::create('/_download', 'GET')];

        return Rsx_File_Gates::authorize(Rsx_File_Gates::THUMBNAIL, $payload) === true
            && Rsx_File_Gates::authorize(Rsx_File_Gates::DOWNLOAD, $payload) === true;
    }

    // ---------------------------------------------------------------------
    // the path
    // ---------------------------------------------------------------------

    public static function test_a_portal_upload_is_claimed_onto_the_thread_and_readable_by_its_client_only()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client);
        $member = static::__make_member($client);

        $other_client = static::__make_client();
        $outsider = static::__make_member($other_client);

        // 1. Upload, as the client, in the portal realm: stored, unclaimed, on this site.
        static::__as_portal($member);
        $uploaded = static::__upload('statement.txt', "Account statement\nBalance: 0\n");

        $attachment = File_Attachment_Model::find_by_key($uploaded['key']);
        static::__assert_not_null($attachment, 'the upload is an attachment row');
        static::__assert_null($attachment->fileable_type, 'unclaimed until a record takes it');
        static::__assert_equals(self::SITE_ID, (int) $attachment->site_id, 'on the portal\'s site');

        // 2. Reply with it: the endpoint claims it onto the thread as a reviewable document.
        $result = Portal_Request_Threads_Controller::reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => 'Statement attached.',
            'attachment_keys' => [$uploaded['key']],
        ]);

        $attachment = File_Attachment_Model::find_by_key($uploaded['key']);
        static::__assert_equals('Portal_Request_Thread_Model', (string) $attachment->fileable_type, 'claimed by the thread');
        static::__assert_equals((int) $thread->id, (int) $attachment->fileable_id);
        static::__assert_equals(Portal_Request_Threads_Controller::DOCUMENTS_THREAD_CATEGORY, (string) $attachment->fileable_category);

        $documents = Portal_Request_Document_Model::where('thread_id', $thread->id)->get();
        static::__assert_count(1, $documents, 'one reviewable document for the file');
        static::__assert_equals((int) $attachment->id, (int) $documents[0]->attachment_id);
        static::__assert_equals((int) $result['message_id'], (int) $documents[0]->message_id, 'linked to the reply that carried it');
        static::__assert_equals(Portal_Request_Document_Model::REVIEW_PENDING, (int) $documents[0]->review_status);

        // 3. Read access: the client's member may read it; another client's member may not.
        static::__assert_true(static::__portal_user_may_read($attachment, $member), 'a member of the thread\'s client may read the file');
        static::__assert_false(static::__portal_user_may_read($attachment, $outsider), 'a member of another client may not');
    }

    public static function test_a_claimed_file_cannot_be_claimed_again_by_another_client()
    {
        $client = static::__make_client();
        $thread = static::__create_thread($client);
        $member = static::__make_member($client);

        static::__as_portal($member);
        $uploaded = static::__upload('invoice.txt', "Invoice 42\n");
        Portal_Request_Threads_Controller::reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => 'Invoice attached.',
            'attachment_keys' => [$uploaded['key']],
        ]);

        // Another client's member, on a thread of their own, presents the same key.
        static::__acting_as_staff();
        $other_client = static::__make_client();
        $other_thread = static::__create_thread($other_client);
        $outsider = static::__make_member($other_client);

        static::__as_portal($outsider);
        Portal_Request_Threads_Controller::reply(new Request(), [
            'thread_id' => $other_thread->id,
            'body' => 'Taking that file.',
            'attachment_keys' => [$uploaded['key']],
        ]);

        $attachment = File_Attachment_Model::find_by_key($uploaded['key']);
        static::__assert_equals((int) $thread->id, (int) $attachment->fileable_id, 'the file stays on the thread that claimed it');
        static::__assert_count(0, Portal_Request_Document_Model::where('thread_id', $other_thread->id)->get(), 'no document on the other thread');
    }
}
