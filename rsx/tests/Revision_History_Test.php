<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Revisions\Revision_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Revisions\Frontend_Revisions_Controller;
use Rsx\Models\Client_Model;

/**
 * Frontend_Revisions_Controller::history() - the read side of the revision history.
 *
 * Three things are pinned, and they are the three ways this endpoint could be wrong:
 *
 *   - it returns the actual field diff for an edit, decoded, under the transaction that
 *     made it;
 *   - a record the caller cannot fetch is NOT FOUND, because the endpoint reads the record
 *     through the model's own fetch() rather than gating separately - a history that leaked
 *     where the record does not would be an enumeration surface;
 *   - a record_type outside the published allowlist is refused, so "read the history of one
 *     client" can never become "read the history of any table".
 */
class Revision_History_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const OTHER_SITE_ID = 2;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * A saved client, with a unique name so an assertion names one record.
     *
     * @return Client_Model
     */
    private static function __seed_client(string $name): Client_Model
    {
        $client = new Client_Model();
        $client->name = $name;
        $client->save();

        return $client;
    }

    public static function test_history_returns_the_diff_for_an_edit()
    {
        $before_name = 'RevisionHistory ' . str_replace('.', '', uniqid('', true));
        $after_name = $before_name . ' Renamed';

        $client = static::__seed_client($before_name);

        $client->name = $after_name;
        $client->save();

        $result = Frontend_Revisions_Controller::history(request(), [
            'record_type' => 'Client_Model',
            'record_id' => (int) $client->id,
        ]);

        static::__assert_true(isset($result['transactions']), 'the endpoint returns a transactions list');
        static::__assert_true(count($result['transactions']) > 0, 'the two writes produced a transaction');

        // Both writes happened in one unit of work (a test is one), so they share a
        // transaction and the rename is the second revision in its sequence.
        $update = null;

        foreach ($result['transactions'] as $transaction) {
            foreach ($transaction['revisions'] as $revision) {
                if ($revision['operation_id'] === Revision_Model::OPERATION_UPDATE) {
                    $update = $revision;
                }
            }
        }

        static::__assert_true($update !== null, 'the rename was recorded as an update');
        static::__assert_equals('Client_Model', $update['record_type'], 'the revision names the record that changed');
        static::__assert_equals((int) $client->id, $update['record_id'], 'the revision names the row that changed');
        static::__assert_true(isset($update['diff']['name']), 'the changed column appears in the diff');
        static::__assert_equals($before_name, $update['diff']['name'][0], 'the diff carries the value before the edit');
        static::__assert_equals($after_name, $update['diff']['name'][1], 'the diff carries the value after the edit');
    }

    public static function test_a_record_the_caller_cannot_fetch_is_not_found()
    {
        static::__acting_as_site(self::OTHER_SITE_ID);
        $other = static::__seed_client('RevisionHistory Other ' . str_replace('.', '', uniqid('', true)));
        $other->name = $other->name . ' Edited';
        $other->save();

        static::__acting_as_site(self::SITE_ID);

        $result = Frontend_Revisions_Controller::history(request(), [
            'record_type' => 'Client_Model',
            'record_id' => (int) $other->id,
        ]);

        static::__assert_instance_of(Error_Response::class, $result, 'another site\'s record has no history here');
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $result->get_error_code(), 'the refusal is indistinguishable from a missing row');
    }

    public static function test_an_unlisted_record_type_is_rejected()
    {
        $result = Frontend_Revisions_Controller::history(request(), [
            'record_type' => 'User_Model',
            'record_id' => 1,
        ]);

        static::__assert_instance_of(Error_Response::class, $result, 'a type outside the allowlist is refused');
        static::__assert_equals(Ajax::ERROR_VALIDATION, $result->get_error_code(), 'an unpublished type is a validation refusal, not a not-found');
    }
}
