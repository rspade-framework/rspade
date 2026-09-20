<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxQuestionException;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;

/**
 * The duplicate-email question on Frontend_Contacts_Controller::save().
 *
 * The app's worked example of VALIDATE -> ASK -> WRITE. What is pinned here is the
 * endpoint's behaviour across the rounds of one submission:
 *
 *   - a create whose email another contact holds ASKS, and writes nothing
 *   - the answer true creates the second contact
 *   - the answer false writes nothing and hands back the existing record
 *   - an EDIT never asks, not even of the record that owns the address
 *
 * The browser half - the dialog, the cancel sentinel, the resubmission - is the
 * framework's: system/app/RSpade/tests/forms/.
 */
class Contact_Duplicate_Question_Test extends Rsx_Test_Abstract
{
    public static function setup(): void
    {
        // Site 1's initial user: save() stamps authorship, and the endpoint's
        // is_logged_in gate is evaluated by the in-process Ajax entry point.
        static::__acting_as_user(1);
    }

    private static function __seed_client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'DuplicateQuestion ' . str_replace('.', '', uniqid('', true));
        $client->save();

        return $client;
    }

    /**
     * An email nobody in the site holds yet.
     */
    private static function __unique_email(): string
    {
        return 'duplicate.question+' . str_replace('.', '', uniqid('', true)) . '@example.com';
    }

    private static function __params(Client_Model $client, string $email, array $extra = []): array
    {
        return array_merge([
            'first_name' => 'Duplicate',
            'last_name' => 'Question',
            'email' => $email,
            'client_id' => (int) $client->id,
        ], $extra);
    }

    /**
     * A contact holding $email, created through the endpoint's own unquestioned path.
     */
    private static function __seed_contact(Client_Model $client, string $email): Contact_Model
    {
        $result = Ajax::internal(
            'Frontend_Contacts_Controller',
            'save',
            static::__params($client, $email, ['first_name' => 'Original'])
        );

        return Contact_Model::find($result['contact_id']);
    }

    public static function test_a_duplicate_email_asks_and_writes_nothing()
    {
        $client = static::__seed_client();
        $email = static::__unique_email();
        static::__seed_contact($client, $email);

        $before = Contact_Model::where('email', $email)->count();

        $thrown = static::__assert_throws(AjaxQuestionException::class, function () use ($client, $email) {
            Ajax::internal('Frontend_Contacts_Controller', 'save', static::__params($client, $email));
        });

        static::__assert_equals('duplicate_email', $thrown->get_key());

        $question = $thrown->get_question();
        static::__assert_equals('confirm', $question['kind']);
        static::__assert_equals('Create anyway', $question['confirm_label']);
        static::__assert_contains('Original', $question['body'], 'the question names the contact already holding the address');

        static::__assert_equals(
            $before,
            Contact_Model::where('email', $email)->count(),
            'asking writes nothing'
        );
    }

    public static function test_the_answer_true_creates_the_second_contact()
    {
        $client = static::__seed_client();
        $email = static::__unique_email();
        static::__seed_contact($client, $email);

        $result = Ajax::internal('Frontend_Contacts_Controller', 'save', static::__params($client, $email, [
            '_answers' => ['duplicate_email' => true],
        ]));

        static::__assert_equals(
            2,
            Contact_Model::where('email', $email)->count(),
            'the address is now held twice, deliberately'
        );

        $created = Contact_Model::find($result['contact_id']);
        static::__assert_equals('Duplicate', $created->first_name, 'the new record is the one that was submitted');
    }

    public static function test_the_answer_false_writes_nothing_and_returns_the_existing_contact()
    {
        $client = static::__seed_client();
        $email = static::__unique_email();
        $existing = static::__seed_contact($client, $email);

        $result = Ajax::internal('Frontend_Contacts_Controller', 'save', static::__params($client, $email, [
            '_answers' => ['duplicate_email' => false],
        ]));

        static::__assert_equals((int) $existing->id, (int) $result['contact_id']);
        static::__assert_equals('/contacts/view/' . $existing->id, $result['redirect']);

        static::__assert_equals(
            1,
            Contact_Model::where('email', $email)->count(),
            'No is an answer the endpoint acts on, and it writes nothing'
        );
    }

    public static function test_editing_the_contact_that_holds_the_address_never_asks()
    {
        $client = static::__seed_client();
        $email = static::__unique_email();
        $existing = static::__seed_contact($client, $email);

        $result = Ajax::internal('Frontend_Contacts_Controller', 'save', static::__params($client, $email, [
            'id' => (int) $existing->id,
            'first_name' => 'Renamed',
        ]));

        static::__assert_equals((int) $existing->id, (int) $result['contact_id']);
        static::__assert_equals('Renamed', Contact_Model::find($existing->id)->first_name);
    }
}
