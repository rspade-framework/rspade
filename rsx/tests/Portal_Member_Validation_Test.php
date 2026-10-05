<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Membership_Model;

/**
 * Staff portal-membership writes accept only what a membership can mean.
 *
 * portal_add_member hands a client's portal only to one of THAT client's contacts, and
 * every membership role write (add, update, bulk invite) accepts only an id of the
 * membership role enum - Portal_Permission::can_collaborate() reads the role as an ordered
 * number, so an arbitrary integer would read as a role nobody granted.
 */
class Portal_Member_Validation_Test extends Rsx_Test_Abstract
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

    private static function __client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Member validation ' . uniqid();
        $client->portal_enabled = true;
        $client->save();

        return $client;
    }

    private static function __contact_with_account(Client_Model $client): Contact_Model
    {
        $contact = new Contact_Model();
        $contact->client_id = $client->id;
        $contact->first_name = 'Member';
        $contact->last_name = 'Candidate';
        $contact->email = 'member_' . uniqid() . '@example.com';
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

        return $contact;
    }

    public static function test_another_clients_contact_is_refused()
    {
        $client = static::__client();
        $stranger = static::__contact_with_account(static::__client());

        $result = Frontend_Clients_Controller::portal_add_member(new Request(), [
            'client_id' => $client->id,
            'contact_id' => $stranger->id,
        ]);

        static::__assert_instance_of(Error_Response::class, $result, "another client's contact is refused");
        static::__assert_equals(0, Portal_Membership_Model::count_for_client($client->id), 'no membership was written');
    }

    public static function test_an_unknown_role_is_refused_and_a_known_one_accepted()
    {
        $client = static::__client();
        $contact = static::__contact_with_account($client);

        $refused = Frontend_Clients_Controller::portal_add_member(new Request(), [
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'role_id' => 99,
        ]);
        static::__assert_instance_of(Error_Response::class, $refused, 'role 99 is refused');

        $added = Frontend_Clients_Controller::portal_add_member(new Request(), [
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'role_id' => Portal_Membership_Model::ROLE_COLLABORATOR,
        ]);
        static::__assert_array_has_key('membership_id', $added, 'an enum role is accepted');

        $updated = Frontend_Clients_Controller::portal_update_role(new Request(), [
            'membership_id' => $added['membership_id'],
            'role_id' => 7,
        ]);
        static::__assert_instance_of(Error_Response::class, $updated, 'updating to role 7 is refused');
        static::__assert_equals(
            Portal_Membership_Model::ROLE_COLLABORATOR,
            (int) Portal_Membership_Model::find($added['membership_id'])->role_id,
            'the role is untouched'
        );

        $invited = Frontend_Clients_Controller::portal_bulk_invite(new Request(), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
            'role_id' => 42,
        ]);
        static::__assert_instance_of(Error_Response::class, $invited, 'a bulk invite with role 42 is refused');
    }
}
