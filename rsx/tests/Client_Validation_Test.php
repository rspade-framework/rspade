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
use Rsx\App\Api\V1\Clients_Api_Controller;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\Models\Client_Model;

/**
 * A client value the record cannot hold is refused as a FIELD error, on the staff form and
 * on the external API alike (Client_Model::value_errors()):
 *
 *   - an over-long value names its field and the column's limit, never a database error
 *   - status_id must be a declared status, preferred_contact_method one of CONTACT_METHODS
 *   - established_year is a year from 1800 to this one
 *   - website, facebook_url and linkedin_url are http(s) addresses only (javascript: refused)
 *   - tags are a list of short, non-blank strings
 *   - the API's create refuses a blank name (a present key is not a value)
 */
class Client_Validation_Test extends Rsx_Test_Abstract
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

    private static function __save(array $values)
    {
        return Frontend_Clients_Controller::save(new Request(), array_merge([
            'name' => 'Client Validation ' . uniqid(),
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

    // ---------------------------------------------------------------------
    // staff form
    // ---------------------------------------------------------------------

    public static function test_an_over_long_value_is_a_field_error()
    {
        $result = static::__save(['zip' => str_repeat('9', Client_Model::field_length('zip') + 1)]);

        static::__assert_field_error($result, 'zip', 'an over-long ZIP is refused');
        static::__assert_contains((string) Client_Model::field_length('zip'), $result->get_metadata()['zip'], 'the message names the limit');
    }

    public static function test_an_undeclared_status_is_refused()
    {
        static::__assert_field_error(static::__save(['status_id' => 999]), 'status_id', 'status 999 is refused');
    }

    public static function test_an_unknown_contact_method_is_refused()
    {
        static::__assert_field_error(static::__save(['preferred_contact_method' => 'pigeon']), 'preferred_contact_method', 'an unknown method is refused');
    }

    public static function test_an_impossible_established_year_is_refused()
    {
        static::__assert_field_error(static::__save(['established_year' => '3000']), 'established_year', 'a future year is refused');
        static::__assert_field_error(static::__save(['established_year' => '12x']), 'established_year', 'a non-year is refused');
    }

    public static function test_only_web_addresses_are_accepted_as_links()
    {
        static::__assert_field_error(static::__save(['facebook_url' => 'javascript:alert(1)']), 'facebook_url', 'a javascript: link is refused');
        static::__assert_field_error(static::__save(['linkedin_url' => 'ftp://example.com/x']), 'linkedin_url', 'an ftp: link is refused');
        static::__assert_field_error(static::__save(['website' => 'ftp://example.com']), 'website', 'an ftp: website is refused');
        static::__assert_field_error(static::__save(['website' => 'javascript:alert(1).com']), 'website', 'a javascript: website is refused');
    }

    public static function test_tags_must_be_a_list_of_short_strings()
    {
        static::__assert_field_error(static::__save(['tags' => [['nested']]]), 'tags', 'a nested array is refused');
        static::__assert_field_error(static::__save(['tags' => [str_repeat('t', Client_Model::TAG_MAX_LENGTH + 1)]]), 'tags', 'an over-long tag is refused');
    }

    public static function test_valid_values_save()
    {
        $result = static::__save([
            'status_id' => (string) Client_Model::STATUS_PROSPECT,
            'preferred_contact_method' => 'phone',
            'established_year' => '1999',
            'website' => 'https://example.com/about',
            'facebook_url' => 'https://facebook.com/example',
            'tags' => ['VIP', 'Enterprise'],
            'zip' => str_repeat('9', Client_Model::field_length('zip')),
        ]);

        static::__assert_array_has_key('client_id', $result, 'the save succeeds');

        $client = Client_Model::find($result['client_id']);
        static::__assert_equals('example.com/about', $client->website, 'the website is stored in short form');
        static::__assert_equals(Client_Model::STATUS_PROSPECT, (int) $client->status_id);
    }

    // ---------------------------------------------------------------------
    // external API
    // ---------------------------------------------------------------------

    private static function __api_create(array $values)
    {
        return Clients_Api_Controller::create(new Request(), array_merge([
            'name' => 'Client API Validation ' . uniqid(),
            'address_country' => 'US',
            'preferred_contact_method' => 'email',
            'status_id' => 1,
            'newsletter_opt_in' => false,
        ], $values));
    }

    public static function test_api_create_refuses_a_blank_name()
    {
        static::__assert_api_field_error(static::__api_create(['name' => '   ']), 'name', 'a blank name is refused');
    }

    public static function test_api_create_refuses_an_over_long_value()
    {
        static::__assert_api_field_error(static::__api_create(['city' => str_repeat('c', Client_Model::field_length('city') + 1)]), 'city', 'an over-long city is refused');
    }

    public static function test_api_create_refuses_values_outside_the_vocabularies()
    {
        static::__assert_api_field_error(static::__api_create(['status_id' => 999]), 'status_id', 'status 999 is refused');
        static::__assert_api_field_error(static::__api_create(['preferred_contact_method' => 'pigeon']), 'preferred_contact_method', 'an unknown method is refused');
        static::__assert_api_field_error(static::__api_create(['linkedin_url' => 'javascript:alert(1)']), 'linkedin_url', 'a javascript: link is refused');
    }

    public static function test_api_update_checks_the_fields_sent()
    {
        $client = new Client_Model();
        $client->name = 'Client API Update ' . uniqid();
        $client->save();

        $response = Clients_Api_Controller::update(new Request(), [
            'id' => (int) $client->id,
            'status_id' => 999,
        ]);

        static::__assert_api_field_error($response, 'status_id', 'an update to status 999 is refused');

        $updated = Clients_Api_Controller::update(new Request(), [
            'id' => (int) $client->id,
            'status_id' => Client_Model::STATUS_ARCHIVED,
        ]);

        static::__assert_equals(Client_Model::STATUS_ARCHIVED, (int) $updated->status_id, 'a declared status is written');
    }

    public static function test_api_create_accepts_valid_values()
    {
        $response = static::__api_create(['status_id' => Client_Model::STATUS_PROSPECT, 'website' => 'http://example.org']);

        static::__assert_equals(201, $response->getStatusCode(), 'the client is created');
    }
}
