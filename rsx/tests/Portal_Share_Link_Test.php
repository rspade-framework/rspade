<?php
/**
 * CODING CONVENTION: snake_case for variable_names and function_names.
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Invitation_Model;
use Rsx\Models\Shared_Item_Model;
use Rsx\Portal\Auth\Portal_Login_Controller;
use Rsx\Portal\Auth\Portal_Share_Link_Controller;

/**
 * Sharing a document with somebody, and what their link does.
 *
 *   - documents_share mints the invitation and SENDS NOTHING; documents_share_send emails the
 *     share, and the invitation as a separate email, once;
 *   - a share link answers by who follows it: the documents page, the sign-in page with a
 *     notice, the accept-invite page, or the sign-in page with an error - and every signed-out
 *     hop carries the link back as the redirect;
 *   - a threaded redirect beats the invited client's workspace.
 */
class Portal_Share_Link_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Rsx_Portal::set_portal_request(false);
        app()->instance('request', Request::create('/'));
        static::__reset_session();
    }

    private static function __make_client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Share Client ' . uniqid();
        $client->portal_enabled = true;
        $client->save();

        return $client;
    }

    private static function __make_contact(Client_Model $client): Contact_Model
    {
        $contact = new Contact_Model();
        $contact->client_id = $client->id;
        $contact->first_name = 'Share';
        $contact->last_name = 'Recipient ' . uniqid();
        $contact->email = 'share_' . uniqid() . '@example.com';
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

    private static function __make_document(Client_Model $client): File_Attachment_Model
    {
        $storage = new File_Storage_Model();
        $storage->hash = 'hash_' . uniqid();
        $storage->size = 1234;
        $storage->save();

        $attachment = new File_Attachment_Model();
        $attachment->key = 'doc_' . bin2hex(random_bytes(8));
        $attachment->file_storage_id = $storage->id;
        $attachment->file_name = 'report.pdf';
        $attachment->site_id = self::SITE_ID;
        $attachment->fileable_type = 'Client_Model';
        $attachment->fileable_id = $client->id;
        $attachment->fileable_category = 'documents';
        $attachment->save();

        return $attachment;
    }

    private static function __share(Client_Model $client, File_Attachment_Model $doc, Contact_Model $contact): array
    {
        return Frontend_Clients_Controller::documents_share(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
            'contact_ids' => [$contact->id],
        ]);
    }

    private static function __send(Client_Model $client, File_Attachment_Model $doc, Contact_Model $contact): array
    {
        return Frontend_Clients_Controller::documents_share_send(new Request(), [
            'client_id' => $client->id,
            'attachment_id' => $doc->id,
            'contact_ids' => [$contact->id],
        ]);
    }

    private static function __emails(string $address, string $class): int
    {
        return Email_Queue_Model::where('to_address', $address)->where('email_class', $class)->count();
    }

    /** Follow a share link as a portal request; the redirect target. */
    private static function __follow(string $token): string
    {
        config(['rsx.portal.url' => '']);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(self::SITE_ID);

        $path = Rsx_Portal::Route('Portal_Share_Link_Controller::open', ['token' => $token]);
        $request = Request::create($path, 'GET');
        app()->instance('request', $request);

        return Portal_Share_Link_Controller::open($request, ['token' => $token])->getTargetUrl();
    }

    private static function __token(File_Attachment_Model $doc, Contact_Model $contact): string
    {
        return Shared_Item_Model::find_valid_share('File_Attachment_Model', (int) $doc->id, (int) $contact->id)->token;
    }

    public static function test_sharing_mints_the_invitation_and_sends_nothing()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $doc = static::__make_document($client);

        $result = static::__share($client, $doc, $contact);

        $invitation = Portal_Invitation_Model::find_pending_for_contact_client((int) $contact->id, (int) $client->id);
        static::__assert_not_null($invitation, 'one invitation, whether or not anything is sent');
        static::__assert_equals(0, Email_Queue_Model::where('to_address', $contact->email)->count(), 'no email yet');

        static::__assert_count(1, $result['recipients']);
        static::__assert_contains('/shared/', $result['recipients'][0]['link'], 'the recipient link is the share route');
        static::__assert_false($result['recipients'][0]['has_account']);
        static::__assert_contains('/shared/', $result['preview']['html'], 'the preview carries a link');
    }

    public static function test_send_emails_the_share_and_the_invitation_separately_and_once()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $doc = static::__make_document($client);
        static::__share($client, $doc, $contact);

        static::__assert_equals(1, static::__send($client, $doc, $contact)['sent']);
        static::__assert_equals(1, static::__emails($contact->email, 'Portal_Shared_Content_Email'), 'the share');
        static::__assert_equals(1, static::__emails($contact->email, 'Portal_Invitation_Email'), 'the invitation, separately');

        static::__send($client, $doc, $contact);
        static::__assert_equals(2, static::__emails($contact->email, 'Portal_Shared_Content_Email'), 'sending again sends the share again');
        static::__assert_equals(1, static::__emails($contact->email, 'Portal_Invitation_Email'), 'but not the invitation');
    }

    public static function test_an_invitation_emailed_elsewhere_is_not_sent_again()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $doc = static::__make_document($client);
        Portal_Invitation_Model::create_invitation(self::SITE_ID, $contact->email, ['contact_id' => $contact->id, 'client_id' => $client->id]);

        static::__share($client, $doc, $contact);
        static::__send($client, $doc, $contact);

        static::__assert_equals(0, static::__emails($contact->email, 'Portal_Invitation_Email'));
    }

    public static function test_an_account_holder_signed_out_is_asked_to_sign_in_and_comes_back()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        static::__make_portal_user($contact);
        $doc = static::__make_document($client);
        static::__share($client, $doc, $contact);

        $target = static::__follow(static::__token($doc, $contact));

        static::__assert_contains('/login', $target);
        static::__assert_contains('notice=sign_in_to_view', $target);
        static::__assert_contains('redirect=', $target, 'the link rides along');
        static::__assert_contains(urlencode('/shared/'), $target);
    }

    public static function test_no_account_with_a_live_invitation_goes_to_accept()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $doc = static::__make_document($client);
        static::__share($client, $doc, $contact);

        $invitation = Portal_Invitation_Model::find_pending_for_contact_client((int) $contact->id, (int) $client->id);
        $target = static::__follow(static::__token($doc, $contact));

        static::__assert_contains('/register', $target);
        static::__assert_contains($invitation->invitation_code, $target);
        static::__assert_contains('redirect=', $target);
    }

    public static function test_no_account_and_no_live_invitation_is_the_invitation_error()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $doc = static::__make_document($client);
        static::__share($client, $doc, $contact);

        Portal_Invitation_Model::find_pending_for_contact_client((int) $contact->id, (int) $client->id)->mark_expired();

        static::__assert_contains('notice=invitation_expired', static::__follow(static::__token($doc, $contact)));
    }

    public static function test_a_dead_share_is_the_share_error()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $doc = static::__make_document($client);
        static::__share($client, $doc, $contact);

        $share = Shared_Item_Model::find_valid_share('File_Attachment_Model', (int) $doc->id, (int) $contact->id);
        $token = $share->token;
        $share->expires_at = now()->subDay();
        $share->save();

        static::__assert_contains('notice=share_expired', static::__follow($token));
        static::__assert_contains('notice=share_expired', static::__follow('no_such_token'));
    }

    public static function test_the_recipient_signed_in_opens_the_documents_and_it_is_recorded()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user($contact);
        $doc = static::__make_document($client);
        static::__share($client, $doc, $contact);

        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::set_portal_user_id($user->id);

        static::__assert_contains('/workspace/' . $client->id, static::__follow(static::__token($doc, $contact)));
        static::__assert_true(
            Shared_Item_Model::find_valid_share('File_Attachment_Model', (int) $doc->id, (int) $contact->id)->is_accessed(),
            'the share records it was opened'
        );
    }

    public static function test_somebody_else_signed_in_is_not_taken_to_the_document()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $other = static::__make_portal_user(static::__make_contact($client));
        $doc = static::__make_document($client);
        static::__share($client, $doc, $contact);

        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::set_portal_user_id($other->id);

        $target = static::__follow(static::__token($doc, $contact));

        static::__assert_false(str_contains($target, '/workspace/'), 'the dashboard, not the documents');
        static::__assert_false(
            Shared_Item_Model::find_valid_share('File_Attachment_Model', (int) $doc->id, (int) $contact->id)->is_accessed(),
            'and the share is not marked opened'
        );
    }

    public static function test_a_threaded_redirect_beats_the_invited_client()
    {
        $client = static::__make_client();
        $contact = static::__make_contact($client);
        $user = static::__make_portal_user($contact);

        static::__assert_equals(
            '/somewhere/parked',
            Portal_Login_Controller::destination($user, (int) $client->id, '/somewhere/parked'),
            'a parked redirect wins'
        );
    }
}
