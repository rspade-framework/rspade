<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Contacts\Frontend_Contacts_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;

/**
 * Server-side phone validation on Frontend_Contacts_Controller::save().
 *
 * The browser widget formats; it does not decide. What is pinned here is the rule the
 * SERVER applies to the three phone fields:
 *
 *   - an unusable number is a per-field form error, on its own field, and nothing saves
 *   - a usable number is stored in E.164, whatever spelling arrived
 *   - a blank field is a VALUE - it clears the column rather than being ignored
 *   - the three fields are independent
 *
 * The bar is libphonenumber's isPossibleNumber(), so a number in an unassigned range
 * (a placeholder area code on a business card) is accepted - see the controller's
 * _normalize_phone() for why.
 */
class Contact_Phone_Validation_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * A client to hang the test contacts off - save() requires one.
     *
     * @return Client_Model
     */
    private static function __seed_client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'PhoneValidation ' . str_replace('.', '', uniqid('', true));
        $client->save();

        return $client;
    }

    /**
     * The save() payload, with the phone fields overridable per case.
     *
     * @param Client_Model $client
     * @param array $phones
     * @return array
     */
    private static function __params(Client_Model $client, array $phones): array
    {
        return array_merge([
            'first_name' => 'Phone',
            'last_name' => 'Tester',
            'email' => 'phone.tester@example.com',
            'client_id' => (int) $client->id,
            'phone_work' => '',
            'phone_cell' => '',
            'phone_other' => '',
        ], $phones);
    }

    public static function test_valid_numbers_are_stored_as_e164()
    {
        $client = static::__seed_client();

        $result = Frontend_Contacts_Controller::save(request(), static::__params($client, [
            'phone_work' => '(920) 614-5140',
            'phone_cell' => '920.614.5141',
            'phone_other' => '+44 20 7123 4567',
        ]));

        static::__assert_true(isset($result['contact_id']), 'a payload of usable numbers saves');

        $contact = Contact_Model::find($result['contact_id']);

        static::__assert_equals('+19206145140', $contact->phone_work, 'a formatted US number normalises');
        static::__assert_equals('+19206145141', $contact->phone_cell, 'a dotted US number normalises to the same shape');
        static::__assert_equals('+442071234567', $contact->phone_other, 'a + number keeps its own country code');
    }

    public static function test_unusable_number_is_a_field_error_and_nothing_saves()
    {
        $client = static::__seed_client();

        $before = Contact_Model::where('client_id', $client->id)->count();

        $result = Frontend_Contacts_Controller::save(request(), static::__params($client, [
            'phone_work' => '123',
        ]));

        static::__assert_instance_of(Error_Response::class, $result, 'an unusable number is a validation error');

        $metadata = $result->get_metadata();

        static::__assert_equals(
            'Please enter a valid phone number',
            $metadata['phone_work'] ?? null,
            'the error is reported against the field it came from'
        );

        static::__assert_false(isset($metadata['phone_cell']), 'the other phone fields are not implicated');

        static::__assert_equals(
            $before,
            Contact_Model::where('client_id', $client->id)->count(),
            'a rejected save writes no record'
        );
    }

    public static function test_each_phone_field_reports_independently()
    {
        $client = static::__seed_client();

        $result = Frontend_Contacts_Controller::save(request(), static::__params($client, [
            'phone_work' => '(920) 614-5140',
            'phone_cell' => 'not a phone number',
            'phone_other' => '5',
        ]));

        static::__assert_instance_of(Error_Response::class, $result);

        $metadata = $result->get_metadata();

        static::__assert_false(isset($metadata['phone_work']), 'the usable number raises nothing');
        static::__assert_true(isset($metadata['phone_cell']), 'unparseable text is reported');
        static::__assert_true(isset($metadata['phone_other']), 'a too-short number is reported');
    }

    public static function test_blank_clears_the_field()
    {
        $client = static::__seed_client();

        $created = Frontend_Contacts_Controller::save(request(), static::__params($client, [
            'phone_work' => '(920) 614-5140',
        ]));

        $contact_id = $created['contact_id'];

        $result = Frontend_Contacts_Controller::save(request(), static::__params($client, [
            'id' => $contact_id,
            'phone_work' => '',
        ]));

        static::__assert_true(isset($result['contact_id']), 'a blank phone field is a valid submission');

        $contact = Contact_Model::find($contact_id);

        static::__assert_null($contact->phone_work, 'submitting the field blank clears the stored number');
    }

    /**
     * A number in an unassigned range is data entry, not a data error. Seeded contacts carry
     * plenty of them, and rejecting them would make an unrelated edit unsaveable.
     */
    public static function test_placeholder_range_is_accepted()
    {
        $client = static::__seed_client();

        $result = Frontend_Contacts_Controller::save(request(), static::__params($client, [
            'phone_work' => '(871) 350-8072',
        ]));

        static::__assert_true(isset($result['contact_id']), 'an unassigned area code still saves');

        $contact = Contact_Model::find($result['contact_id']);

        static::__assert_equals('+18713508072', $contact->phone_work);
    }
}
