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
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Models\Shared_Item_Model;
use Rsx\Portal\Workspaces\Documents\Portal_Documents_Controller;

/**
 * Stage 2 Documents (firm -> client share) tests.
 *
 * Proves the share + portal-read contract end to end at the controller layer:
 *   - documents_share creates one Shared_Item per selected contact (idempotent).
 *   - the portal Portal_Documents_Controller.list() returns ONLY the caller's shared
 *     docs for the requested client - membership-gated AND contact-scoped.
 *   - a non-member is denied; a member with a DIFFERENT contact sees nothing.
 *   - unshare removes portal access.
 *
 * Staff operations run under __acting_as_site (site-scoped models). Portal reads run
 * under a CLI portal session set via Portal_Session::set_portal_user_id.
 */
class Portal_Documents_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Portal_Session::reset();
        static::__reset_session();
    }

    // ---------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------

    private static function __make_client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Docs Client ' . uniqid();
        $client->portal_enabled = true;
        $client->save();

        return $client;
    }

    private static function __make_contact(Client_Model $client): Contact_Model
    {
        $contact = new Contact_Model();
        $contact->client_id = $client->id;
        $contact->first_name = 'Doc';
        $contact->last_name = 'Recipient ' . uniqid();
        $contact->email = 'doc_' . uniqid() . '@example.com';
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

    private static function __add_member(Client_Model $client, Portal_User_Model $user): Portal_Membership_Model
    {
        $membership = new Portal_Membership_Model();
        $membership->portal_user_id = $user->id;
        $membership->client_id = $client->id;
        $membership->role_id = Portal_Membership_Model::ROLE_VIEWER;
        $membership->save();

        return $membership;
    }

    /**
     * Create a document attachment (no disk file needed for these tests) attached to
     * the client under the 'documents' category.
     */
    private static function __make_document(Client_Model $client, string $name = 'report.pdf'): File_Attachment_Model
    {
        $storage = new File_Storage_Model();
        $storage->hash = 'hash_' . uniqid();
        $storage->size = 1234;
        $storage->save();

        $attachment = new File_Attachment_Model();
        $attachment->key = 'doc_' . bin2hex(random_bytes(8));
        $attachment->file_storage_id = $storage->id;
        $attachment->file_name = $name;
        $attachment->site_id = self::SITE_ID;
        $attachment->fileable_type = 'Client_Model';
        $attachment->fileable_id = $client->id;
        $attachment->fileable_category = 'documents';
        $attachment->save();

        return $attachment;
    }

    private static function __login_portal(Portal_User_Model $user): void
    {
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::set_portal_user_id($user->id);
    }

    // ---------------------------------------------------------------------
    // sharing creates a Shared_Item per selected contact (idempotent)
    // ---------------------------------------------------------------------

    public static function test_share_creates_shared_item_per_contact()
    {
        $client = static::__make_client();
        $c1 = static::__make_contact($client);
        $c2 = static::__make_contact($client);
        static::__make_portal_user($c1); // c1 has a portal account
        // c2 has no portal account -> will be invited
        $doc = static::__make_document($client);

        $result = Frontend_Clients_Controller::documents_share(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
            'contact_ids' => [$c1->id, $c2->id],
            'message' => 'Please review',
        ]);

        static::__assert_equals(2, $result['shared'], 'one share created per contact');

        $shares = Shared_Item_Model::where('item_type', 'File_Attachment_Model')
            ->where('item_id', $doc->id)
            ->get();
        static::__assert_count(2, $shares, 'two Shared_Item rows exist');

        // Re-sharing is idempotent: no new rows, both counted as already shared.
        $again = Frontend_Clients_Controller::documents_share(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
            'contact_ids' => [$c1->id, $c2->id],
        ]);
        static::__assert_equals(0, $again['shared'], 're-share creates nothing new');
        static::__assert_equals(2, $again['already_shared'], 'both already shared');
    }

    // ---------------------------------------------------------------------
    // portal list() returns ONLY the caller's shared docs for the client
    // ---------------------------------------------------------------------

    public static function test_portal_list_returns_only_callers_shared_docs()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user($contact);
        static::__add_member($client, $user);
        $doc = static::__make_document($client, 'shared.pdf');

        // Share with this contact.
        Frontend_Clients_Controller::documents_share(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
            'contact_ids' => [$contact->id],
        ]);

        static::__login_portal($user);
        $result = Portal_Documents_Controller::list(new Request(), ['client_id' => $client->id]);

        static::__assert_count(1, $result['documents'], 'caller sees the one shared doc');
        static::__assert_equals('shared.pdf', $result['documents'][0]['name']);
        static::__assert_true(strpos($result['documents'][0]['download_url'], $doc->key) !== false, 'download url carries the key');
    }

    public static function test_portal_list_excludes_unshared_doc()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user($contact);
        static::__add_member($client, $user);

        // A document of the client that is NOT shared with this contact.
        static::__make_document($client, 'private.pdf');

        static::__login_portal($user);
        $result = Portal_Documents_Controller::list(new Request(), ['client_id' => $client->id]);

        static::__assert_count(0, $result['documents'], 'an unshared client doc is not visible');
    }

    // ---------------------------------------------------------------------
    // membership + contact scoping
    // ---------------------------------------------------------------------

    public static function test_non_member_is_denied()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user($contact);
        // NOTE: no membership added for $user on $client.
        $doc = static::__make_document($client);
        Frontend_Clients_Controller::documents_share(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
            'contact_ids' => [$contact->id],
        ]);

        static::__login_portal($user);
        $response = Portal_Documents_Controller::list(new Request(), ['client_id' => $client->id]);

        // Fail-closed: the membership gate returns an unauthorized response, not a list.
        static::__assert_false(is_array($response) && isset($response['documents']), 'non-member does not get a documents list');
    }

    public static function test_other_member_sees_client_shared_doc()
    {
        // CLIENT-LEVEL SHARING: sharing a document with one contact shares it with the
        // whole client, so any OTHER member of that client sees it too (the invited
        // contact is merely the one who gets emailed).
        $client = static::__make_client();

        // The contact the doc is invited/shared to.
        $recipient_contact = static::__make_contact($client);
        static::__make_portal_user($recipient_contact);
        $doc = static::__make_document($client);
        Frontend_Clients_Controller::documents_share(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
            'contact_ids' => [$recipient_contact->id],
        ]);

        // A DIFFERENT member of the same client (different contact). The doc was not
        // shared with THEM specifically, but it is shared with the client.
        $other_contact = static::__make_contact($client);
        $other_user = static::__make_portal_user($other_contact);
        static::__add_member($client, $other_user);

        static::__login_portal($other_user);
        $result = Portal_Documents_Controller::list(new Request(), ['client_id' => $client->id]);

        static::__assert_count(1, $result['documents'], 'a fellow member sees the client-shared doc');
        static::__assert_equals($doc->file_name, $result['documents'][0]['name']);
    }

    // ---------------------------------------------------------------------
    // unshare removes access
    // ---------------------------------------------------------------------

    public static function test_unshare_removes_access()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user($contact);
        static::__add_member($client, $user);
        $doc = static::__make_document($client);

        Frontend_Clients_Controller::documents_share(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
            'contact_ids' => [$contact->id],
        ]);

        $share = Shared_Item_Model::where('item_type', 'File_Attachment_Model')
            ->where('item_id', $doc->id)
            ->where('contact_id', $contact->id)
            ->first();
        static::__assert_not_empty($share, 'share exists before unshare');

        // Staff removes the share.
        Frontend_Clients_Controller::documents_unshare(new Request(), [
            'shared_item_id' => $share->id,
        ]);

        static::__login_portal($user);
        $result = Portal_Documents_Controller::list(new Request(), ['client_id' => $client->id]);
        static::__assert_count(0, $result['documents'], 'unshared doc is no longer visible to the portal user');
    }

    // ---------------------------------------------------------------------
    // document_delete removes the doc and all its shares
    // ---------------------------------------------------------------------

    public static function test_delete_removes_doc_and_shares()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user($contact);
        static::__add_member($client, $user);
        $doc = static::__make_document($client);
        Frontend_Clients_Controller::documents_share(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
            'contact_ids' => [$contact->id],
        ]);

        Frontend_Clients_Controller::document_delete(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
        ]);

        $remaining_shares = Shared_Item_Model::where('item_type', 'File_Attachment_Model')
            ->where('item_id', $doc->id)
            ->count();
        static::__assert_equals(0, $remaining_shares, 'all shares removed on delete');

        // delete() now SOFT-deletes into the retention window (it no longer detaches):
        // hidden from the active scope, but recoverable - the fileable link is KEPT and
        // deleted_at is stamped.
        static::__assert_null(File_Attachment_Model::find($doc->id), 'soft-deleted doc is hidden from the active scope');
        $trashed = File_Attachment_Model::withTrashed()->find($doc->id);
        static::__assert_not_null($trashed->deleted_at, 'deleted_at stamped (in retention)');
        static::__assert_null($trashed->destroyed_at, 'not yet destroyed - still recoverable');
        static::__assert_equals((int) $client->id, (int) $trashed->fileable_id, 'fileable link kept (still this client\'s document)');
    }

    // ---------------------------------------------------------------------
    // Retention / recovery (file-disposal lifecycle consumer)
    // ---------------------------------------------------------------------

    /**
     * A document with a REAL backing blob (undelete() verifies the bytes still exist, so
     * the fake-storage __make_document fixture cannot be restored). Uses create_from_string
     * to write an actual blob (relocated to test-storage by the harness), then attaches it.
     */
    private static function __make_real_document(Client_Model $client, string $name = 'recover.txt'): File_Attachment_Model
    {
        $attachment = File_Attachment_Model::create_from_string('doc-body-' . uniqid(), $name, ['site_id' => self::SITE_ID]);
        $attachment->fileable_type = 'Client_Model';
        $attachment->fileable_id = $client->id;
        $attachment->fileable_category = 'documents';
        $attachment->save();

        return $attachment;
    }

    public static function test_delete_enters_retention_then_restore_recovers()
    {
        $client = static::__make_client();
        $doc = static::__make_real_document($client, 'recover.txt');

        static::__assert_count(1, $client->get_attachments('documents'), 'active list has the doc');
        static::__assert_count(0, $client->get_deleted_attachments('documents'), 'nothing deleted yet');

        Frontend_Clients_Controller::document_delete(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
        ]);

        // Out of the active list, into the recently-deleted list.
        static::__assert_count(0, $client->get_attachments('documents'), 'deleted doc dropped from the active list');
        $deleted = Frontend_Clients_Controller::documents_deleted_list(new Request(), ['id' => $client->id]);
        static::__assert_count(1, $deleted['deleted_documents'], 'doc appears in the recently-deleted list');
        static::__assert_equals((int) $doc->id, (int) $deleted['deleted_documents'][0]['attachment_id'], 'the right doc is listed');

        // Restore brings it back to the active list and out of the deleted list.
        $restored = Frontend_Clients_Controller::document_restore(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
        ]);
        static::__assert_equals('Document restored', $restored['message'] ?? '', 'restore succeeds');
        static::__assert_count(1, $client->get_attachments('documents'), 'restored doc is active again');
        $deleted_after = Frontend_Clients_Controller::documents_deleted_list(new Request(), ['id' => $client->id]);
        static::__assert_count(0, $deleted_after['deleted_documents'], 'no longer in the recently-deleted list');
    }

    public static function test_restore_is_scoped_to_the_owning_client()
    {
        $client = static::__make_client();
        $other = static::__make_client();
        $doc = static::__make_real_document($client, 'scoped.txt');

        Frontend_Clients_Controller::document_delete(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
        ]);

        // Restoring under the WRONG client must be rejected (the trashed resolver is scoped).
        $wrong = Frontend_Clients_Controller::document_restore(new Request(), [
            'client_id' => $other->id,
            'attachment_id' => $doc->id,
        ]);
        static::__assert_false(is_array($wrong) && ($wrong['message'] ?? null) === 'Document restored', 'restore under the wrong client does not succeed');
        static::__assert_null(File_Attachment_Model::find($doc->id), 'doc stays soft-deleted after the rejected restore');
    }
}
