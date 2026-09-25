<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Api\V1\Contacts_Api_Controller;
use Rsx\Models\Client_Model;

/**
 * The contacts API accepts only a client_id of the caller's own site.
 *
 * Client_Model is site-scoped, so the endpoint's find() answers null for another site's
 * client exactly as for one that does not exist, and create/update answer the 422
 * validation error naming client_id - a contact can never be attached to a foreign tenant's
 * client. Invoked at the controller layer (dispatch and param coercion are framework-tested).
 */
class Contacts_Api_Client_Site_Test extends Rsx_Test_Abstract
{
    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __client_on_site(int $site_id): Client_Model
    {
        static::__acting_as_site($site_id);
        $client = new Client_Model();
        $client->name = 'Contacts API site probe ' . uniqid();
        $client->save();
        static::__acting_as_site(1);

        return $client;
    }

    private static function __create(int $client_id)
    {
        return Contacts_Api_Controller::create(new Request(), [
            'first_name' => 'Site',
            'last_name' => 'Probe',
            'email' => 'site_probe_' . uniqid() . '@example.com',
            'client_id' => $client_id,
            'is_active' => true,
            'priority' => 2,
        ]);
    }

    public static function test_a_foreign_sites_client_is_refused()
    {
        $foreign = static::__client_on_site(2);

        $response = static::__create((int) $foreign->id);

        static::__assert_equals(422, $response->getStatusCode());
        static::__assert_contains('client_id', $response->getContent());
    }

    public static function test_an_own_sites_client_is_accepted()
    {
        $own = static::__client_on_site(1);

        $response = static::__create((int) $own->id);

        static::__assert_true(!($response instanceof \Symfony\Component\HttpFoundation\Response) || $response->getStatusCode() < 300, 'the contact is created');
    }
}
