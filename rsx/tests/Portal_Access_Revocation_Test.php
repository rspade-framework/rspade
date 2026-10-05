<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Handlers\Portal_File_Access_Handlers;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Models\Shared_Item_Model;
use Rsx\Portal_Permission;

/**
 * Portal access follows the CLIENT, and file reads follow the REALM.
 *
 * A membership row grants portal access only while its client is live - portal_enabled on
 * and not soft-deleted - so "Disable Portal" and deleting a client revoke every member at
 * once, with every membership row kept and the access returning when the portal reopens.
 * The staff side still sees every row (has_membership_row / get_all_for_user), which is what
 * stops a re-invite on a closed portal from creating a duplicate.
 *
 * The file gate (Portal_File_Access_Handlers) answers a portal-realm request for the portal
 * identity only - a staff session never opens a portal file URL, and a disabled staff member
 * gets nothing on either realm - and a portal user reads a client document only through an
 * unexpired share naming their own contact.
 */
class Portal_Access_Revocation_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Rsx_Portal::set_portal_request(false);
        Portal_Session::cli_set_portal_user_id(0);
        static::__reset_session();
    }

    // ---------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------

    private static function __make_client(bool $portal_enabled = true): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Revocation Client ' . uniqid();
        $client->portal_enabled = $portal_enabled;
        $client->save();

        return $client;
    }

    private static function __make_contact(Client_Model $client): Contact_Model
    {
        $contact = new Contact_Model();
        $contact->client_id = $client->id;
        $contact->first_name = 'Rev';
        $contact->last_name = 'Contact ' . uniqid();
        $contact->email = 'rev_' . uniqid() . '@example.com';
        $contact->is_active = true;
        $contact->save();

        return $contact;
    }

    private static function __make_portal_user(?Contact_Model $contact = null): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = $contact ? $contact->email : 'rev_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        if ($contact) {
            $user->contact_id = $contact->id;
        }
        $user->save();

        return $user;
    }

    private static function __add_member(Client_Model $client, Portal_User_Model $user): void
    {
        $membership = new Portal_Membership_Model();
        $membership->portal_user_id = $user->id;
        $membership->client_id = $client->id;
        $membership->role_id = Portal_Membership_Model::ROLE_COLLABORATOR;
        $membership->save();
    }

    private static function __make_document(Client_Model $client): File_Attachment_Model
    {
        $storage = new File_Storage_Model();
        $storage->hash = 'hash_' . uniqid();
        $storage->size = 1234;
        $storage->save();

        $attachment = new File_Attachment_Model();
        $attachment->key = 'rev_' . bin2hex(random_bytes(8));
        $attachment->file_storage_id = $storage->id;
        $attachment->file_name = 'revocation.pdf';
        $attachment->site_id = self::SITE_ID;
        $attachment->fileable_type = 'Client_Model';
        $attachment->fileable_id = $client->id;
        $attachment->fileable_category = 'documents';
        $attachment->save();

        return $attachment;
    }

    private static function __share(File_Attachment_Model $doc, Contact_Model $contact, string $expires_at): Shared_Item_Model
    {
        $share = new Shared_Item_Model();
        $share->shared_by = 1;
        $share->contact_id = $contact->id;
        $share->item_type = 'File_Attachment_Model';
        $share->item_id = $doc->id;
        $share->token = Shared_Item_Model::generate_token();
        $share->expires_at = $expires_at;
        $share->save();

        return $share;
    }

    private static function __staff_user(bool $is_enabled): User_Model
    {
        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'staff_' . uniqid() . '@example.com';
        $user->first_name = 'Staff';
        $user->last_name = 'Reader';
        $user->role_id = User_Model::ROLE_USER;
        $user->is_enabled = $is_enabled ? 1 : 0;
        $user->save();

        return $user;
    }

    private static function __login_portal(Portal_User_Model $user): void
    {
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($user->id);
    }

    private static function __gate(File_Attachment_Model $doc, $user): bool
    {
        return Portal_File_Access_Handlers::authorize_download(['attachment' => $doc, 'user' => $user]) === true;
    }

    // ---------------------------------------------------------------------
    // membership follows the client
    // ---------------------------------------------------------------------

    public static function test_disabling_the_portal_revokes_access_and_keeps_the_row()
    {
        $client = static::__make_client();
        $user = static::__make_portal_user();
        static::__add_member($client, $user);

        static::__login_portal($user);
        static::__assert_true(Portal_Permission::has_client_access($client->id), 'an open portal grants access');
        static::__assert_true(
            in_array($client->id, Portal_Permission::accessible_client_ids(), true),
            'the open client is listed'
        );

        $client->portal_enabled = false;
        $client->save();

        static::__assert_false(Portal_Permission::has_client_access($client->id), 'a closed portal grants nothing');
        static::__assert_null(Portal_Permission::client_role($client->id), 'no role on a closed portal');
        static::__assert_false(Portal_Permission::can_collaborate($client->id), 'no collaboration on a closed portal');
        static::__assert_false(
            in_array($client->id, Portal_Permission::accessible_client_ids(), true),
            'the closed client is not listed'
        );
        static::__assert_true(
            Portal_Membership_Model::has_membership_row($user->id, $client->id),
            'the membership row is kept'
        );
        static::__assert_count(1, Portal_Membership_Model::get_all_for_user($user->id), 'staff still see the row');

        $client->portal_enabled = true;
        $client->save();

        static::__assert_true(Portal_Permission::has_client_access($client->id), 'reopening the portal restores access');
    }

    public static function test_deleting_the_client_revokes_access()
    {
        $client = static::__make_client();
        $user = static::__make_portal_user();
        static::__add_member($client, $user);

        static::__login_portal($user);
        static::__assert_true(Portal_Permission::has_client_access($client->id), 'a live client grants access');

        $client->delete();

        static::__assert_false(Portal_Permission::has_client_access($client->id), 'a deleted client grants nothing');
        static::__assert_null(
            Portal_Membership_Model::find_for_user_and_client($user->id, $client->id),
            'no live membership of a deleted client'
        );
        static::__assert_true(
            Portal_Membership_Model::has_membership_row($user->id, $client->id),
            'the membership row survives the delete'
        );
    }

    // ---------------------------------------------------------------------
    // the file gate follows the realm
    // ---------------------------------------------------------------------

    public static function test_staff_realm_requires_an_active_membership()
    {
        $client = static::__make_client();
        $doc = static::__make_document($client);

        Rsx_Portal::set_portal_request(false);

        static::__assert_true(static::__gate($doc, static::__staff_user(true)), 'an active staff member reads the file');
        static::__assert_false(static::__gate($doc, static::__staff_user(false)), 'a disabled staff member reads nothing');
        static::__assert_false(static::__gate($doc, null), 'nobody reads nothing');
    }

    public static function test_portal_realm_never_admits_a_staff_identity()
    {
        $client = static::__make_client();
        $doc = static::__make_document($client);
        $staff = static::__staff_user(true);

        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(self::SITE_ID);

        static::__assert_false(
            static::__gate($doc, $staff),
            'a staff identity on a portal file URL reads nothing'
        );
    }

    public static function test_portal_realm_reads_only_a_live_share_of_the_callers_contact()
    {
        $client = static::__make_client();
        $recipient_contact = static::__make_contact($client);
        $other_contact = static::__make_contact($client);
        $recipient = static::__make_portal_user($recipient_contact);
        $other = static::__make_portal_user($other_contact);
        static::__add_member($client, $recipient);
        static::__add_member($client, $other);

        $doc = static::__make_document($client);
        $share = static::__share($doc, $recipient_contact, now()->addDays(5)->toDateTimeString());

        Rsx_Portal::set_portal_request(true);

        static::__login_portal($recipient);
        static::__assert_true(static::__gate($doc, $recipient), 'the recipient reads a live share');

        static::__login_portal($other);
        static::__assert_false(static::__gate($doc, $other), 'another member of the client reads nothing');

        $share->expires_at = now()->subDay()->toDateTimeString();
        $share->save();

        static::__login_portal($recipient);
        static::__assert_false(static::__gate($doc, $recipient), 'an expired share reads nothing');
    }

    public static function test_portal_realm_file_access_ends_with_the_portal()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user($contact);
        static::__add_member($client, $user);
        $doc = static::__make_document($client);
        static::__share($doc, $contact, now()->addDays(5)->toDateTimeString());

        Rsx_Portal::set_portal_request(true);
        static::__login_portal($user);
        static::__assert_true(static::__gate($doc, $user), 'readable while the portal is open');

        $client->portal_enabled = false;
        $client->save();

        static::__assert_false(static::__gate($doc, $user), 'not readable once the portal is closed');
    }
}
