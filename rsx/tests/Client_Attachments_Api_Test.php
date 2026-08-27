<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Api\V1\Clients_Api_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Shared_Item_Model;

/**
 * The APP-OWNED half of the external file API: attach / list / remove.
 *
 * The framework moves bytes (POST /api/v1/files) and deliberately does not own "attach this
 * file to that record". These three endpoints are the template's worked example of the half
 * an application writes, and this test pins the three properties that make it safe:
 *
 *   - a key may be claimed ONCE (re-claiming an attached key is refused);
 *   - removal resolves through find_attachment(), so an attachment id belonging to ANOTHER
 *     client cannot be deleted through this client's URL - the defect the ownership
 *     re-verification exists to prevent;
 *   - removal revokes the document's portal shares BEFORE soft-deleting, so no Shared_Item
 *     is left pointing at a deleted document.
 *
 * Endpoints are invoked directly at the controller layer (the dispatcher's auth and param
 * coercion are framework-tested elsewhere); the returned value is the endpoint's own contract.
 */
class Client_Attachments_Api_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    /** A real contact - shared_items.contact_id carries a foreign key to contacts. */
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

    private static function __make_client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'API Attachments Client ' . uniqid();
        $client->save();

        return $client;
    }

    /** An unclaimed upload, exactly as POST /api/v1/files would leave it. */
    private static function __make_unclaimed(string $name = 'contract.pdf'): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string(
            'api-attach-' . uniqid(),
            $name,
            ['site_id' => self::SITE_ID]
        );
    }

    private static function __attach(Client_Model $client, File_Attachment_Model $file)
    {
        return Clients_Api_Controller::attachments_attach(new Request(), [
            'id' => $client->id,
            'key' => $file->key,
        ]);
    }

    // --- the happy path --------------------------------------------------------------------------

    /** Claim an uploaded key onto a client, then see it in the list. */
    public static function test_attach_then_list()
    {
        $client = static::__make_client();
        $file = static::__make_unclaimed();

        $created = static::__attach($client, $file);
        $payload = static::__unwrap($created);

        static::__assert_equals((int) $file->id, (int) $payload['attachment_id'], 'the claimed attachment is returned');
        static::__assert_equals('contract.pdf', $payload['file_name'], 'the payload names the file');

        $listed = Clients_Api_Controller::attachments_list(new Request(), ['id' => $client->id]);

        static::__assert_equals(1, $listed['meta']['total'], 'the document is listed');
        static::__assert_equals(
            (int) $file->id,
            (int) $listed['items'][0]['attachment_id'],
            'and it is the one that was attached'
        );
    }

    /**
     * The API writes into the SAME category the staff Documents tab reads - that is the point
     * of the example, and a regression here would make API uploads invisible in the UI.
     */
    public static function test_attach_lands_in_the_staff_documents_category()
    {
        $client = static::__make_client();
        $file = static::__make_unclaimed();

        static::__attach($client, $file);

        static::__assert_count(
            1,
            $client->get_attachments(Client_Model::DOCUMENTS_CATEGORY),
            'the API attaches into the category the staff UI reads'
        );
    }

    // --- the refusals ----------------------------------------------------------------------------

    /** SINGLE CLAIM: a key is spent on first claim and can never be re-pointed. */
    public static function test_reclaiming_an_attached_key_is_refused()
    {
        $client_one = static::__make_client();
        $client_two = static::__make_client();
        $file = static::__make_unclaimed();

        static::__attach($client_one, $file);
        $second = static::__attach($client_two, $file);

        static::__assert_count(
            0,
            $client_two->get_attachments(Client_Model::DOCUMENTS_CATEGORY),
            'the second client got nothing'
        );
        static::__assert_count(
            1,
            $client_one->get_attachments(Client_Model::DOCUMENTS_CATEGORY),
            'and the first still holds it'
        );
        static::__assert_not_null($second, 'the refusal is a response, not a silent success');
    }

    /**
     * THE DEFECT find_attachment() PREVENTS. The id is real, live and in this tenant - it just
     * belongs to a different client. Resolving with a bare find() would delete it.
     */
    public static function test_cannot_delete_another_clients_attachment()
    {
        $owner = static::__make_client();
        $attacker = static::__make_client();
        $file = static::__make_unclaimed();

        static::__attach($owner, $file);

        Clients_Api_Controller::attachments_delete(new Request(), [
            'id' => $attacker->id,
            'attachment_id' => $file->id,
        ]);

        static::__assert_count(
            1,
            $owner->get_attachments(Client_Model::DOCUMENTS_CATEGORY),
            'the owning client still has its document - the foreign delete was refused'
        );
    }

    // --- removal ---------------------------------------------------------------------------------

    /** Removal enters the retention window rather than destroying anything. */
    public static function test_delete_enters_retention()
    {
        $client = static::__make_client();
        $file = static::__make_unclaimed();

        static::__attach($client, $file);

        Clients_Api_Controller::attachments_delete(new Request(), [
            'id' => $client->id,
            'attachment_id' => $file->id,
        ]);

        static::__assert_count(0, $client->get_attachments(Client_Model::DOCUMENTS_CATEGORY), 'gone from the live list');
        static::__assert_count(1, $client->get_deleted_attachments(Client_Model::DOCUMENTS_CATEGORY), 'in retention');

        $row = File_Attachment_Model::withTrashed()->find($file->id);
        static::__assert_not_null($row, 'the row survives');
        static::__assert_null($row->destroyed_at, 'and is NOT destroyed');
    }

    /**
     * BOTH STEPS, IN ORDER. A document shared with a client's portal users must have those
     * shares revoked as it is deleted - otherwise the portal keeps listing a deleted document.
     */
    public static function test_delete_revokes_portal_shares()
    {
        $client = static::__make_client();
        $file = static::__make_unclaimed();

        static::__attach($client, $file);

        $contact = static::__make_contact($client);

        $share = new Shared_Item_Model();
        $share->item_type = 'File_Attachment_Model';
        $share->item_id = $file->id;
        $share->contact_id = $contact->id;
        $share->shared_by = 1;
        $share->token = 'api-attach-test-' . uniqid();
        $share->save();

        static::__assert_equals(
            1,
            Shared_Item_Model::where('item_type', 'File_Attachment_Model')->where('item_id', $file->id)->count(),
            'the share exists before the delete'
        );

        Clients_Api_Controller::attachments_delete(new Request(), [
            'id' => $client->id,
            'attachment_id' => $file->id,
        ]);

        static::__assert_equals(
            0,
            Shared_Item_Model::where('item_type', 'File_Attachment_Model')->where('item_id', $file->id)->count(),
            'no share survives a deleted document'
        );
    }

    /**
     * Endpoint returns are either a plain array or an Rsx_Api helper response; this reads the
     * payload out of either so the assertions above do not depend on which.
     */
    private static function __unwrap($response): array
    {
        if (is_array($response)) {
            return $response;
        }

        $data = $response->getData(true);

        return is_array($data) ? $data : [];
    }
}
