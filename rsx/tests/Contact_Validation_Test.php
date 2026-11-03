<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Api\V1\Contacts_Api_Controller;
use Rsx\App\Frontend\Contacts\Frontend_Contacts_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;

/**
 * A contact value the record cannot hold is refused as a FIELD error, on the staff form and
 * on the external API alike (Contact_Model::value_errors()): an over-long value, a
 * malformed secondary email, an undeclared priority. The API's create refuses a blank name.
 */
class Contact_Validation_Test extends Rsx_Test_Abstract
{
    public static function setup(): void
    {
        static::__acting_as_site(1);
        static::__acting_as_user(1);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Contact Validation ' . uniqid();
        $client->save();

        return $client;
    }

    private static function __save(array $values)
    {
        return Frontend_Contacts_Controller::save(new Request(), array_merge([
            'first_name' => 'Valid',
            'last_name' => 'Contact',
            'email' => 'contact_validation_' . uniqid() . '@example.com',
            'client_id' => static::__client()->id,
        ], $values));
    }

    private static function __api_create(array $values)
    {
        return Contacts_Api_Controller::create(new Request(), array_merge([
            'first_name' => 'Api',
            'last_name' => 'Contact',
            'email' => 'contact_api_validation_' . uniqid() . '@example.com',
            'client_id' => (int) static::__client()->id,
            'is_active' => true,
            'priority' => 2,
        ], $values));
    }

    private static function __assert_field_error($result, string $field, string $message): void
    {
        static::__assert_instance_of(Error_Response::class, $result, $message);
        static::__assert_array_has_key($field, $result->get_metadata(), "{$message} (on {$field})");
    }

    private static function __assert_api_field_error($response, string $field, string $message): void
    {
        static::__assert_equals(422, $response->getStatusCode(), $message);
        static::__assert_contains($field, $response->getContent(), "{$message} (names {$field})");
    }

    public static function test_staff_save_refuses_an_over_long_value()
    {
        static::__assert_field_error(static::__save(['title' => str_repeat('t', Contact_Model::field_length('title') + 1)]), 'title', 'an over-long title is refused');
        static::__assert_field_error(static::__save(['zip' => str_repeat('9', Contact_Model::field_length('zip') + 1)]), 'zip', 'an over-long ZIP is refused');
    }

    public static function test_staff_save_refuses_a_malformed_secondary_email()
    {
        static::__assert_field_error(static::__save(['email_secondary' => 'not an address']), 'email_secondary', 'a malformed secondary email is refused');
    }

    public static function test_staff_save_refuses_an_undeclared_priority()
    {
        static::__assert_field_error(static::__save(['priority' => '999']), 'priority', 'priority 999 is refused');
    }

    public static function test_staff_save_accepts_valid_values()
    {
        $result = static::__save([
            'title' => str_repeat('t', Contact_Model::field_length('title')),
            'email_secondary' => 'second@example.com',
            'priority' => (string) Contact_Model::PRIORITY_HIGH,
        ]);

        static::__assert_array_has_key('contact_id', $result, 'the save succeeds');
    }

    public static function test_api_create_refuses_a_blank_name()
    {
        static::__assert_api_field_error(static::__api_create(['first_name' => '  ']), 'first_name', 'a blank first name is refused');
        static::__assert_api_field_error(static::__api_create(['last_name' => '']), 'last_name', 'a blank last name is refused');
    }

    public static function test_api_create_refuses_bad_values()
    {
        static::__assert_api_field_error(static::__api_create(['city' => str_repeat('c', Contact_Model::field_length('city') + 1)]), 'city', 'an over-long city is refused');
        static::__assert_api_field_error(static::__api_create(['email_secondary' => 'nope']), 'email_secondary', 'a malformed secondary email is refused');
        static::__assert_api_field_error(static::__api_create(['priority' => 999]), 'priority', 'priority 999 is refused');
    }

    public static function test_api_update_checks_the_fields_sent()
    {
        $created = static::__api_create([]);
        $id = json_decode($created->getContent(), true)['id'];

        $response = Contacts_Api_Controller::update(new Request(), [
            'id' => $id,
            'last_name' => str_repeat('l', Contact_Model::field_length('last_name') + 1),
        ]);

        static::__assert_api_field_error($response, 'last_name', 'an over-long last name is refused on update');
    }
}
