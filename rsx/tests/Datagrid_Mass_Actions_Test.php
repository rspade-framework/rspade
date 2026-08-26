<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\Models\Client_Model;

/**
 * Datagrid mass actions - selection resolution.
 *
 * The whole risk in a mass action is that the SET the server resolves is not the set the
 * user selected: the client sends a mode plus a short id list plus the filters that were
 * on screen, and the server rebuilds the query from those three things. These tests pin
 * all three shapes against the clients grid endpoints:
 *
 *   additive    - exactly the named ids, nothing else
 *   subtractive - everything the filters matched EXCEPT the named ids
 *   all         - everything the filters matched, and the filters are honoured
 *
 * Every row is seeded with a distinguishing name prefix so the assertions can talk about
 * "this test's clients" without caring what else lives in the baseline database.
 */
class Datagrid_Mass_Actions_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * Search term that isolates this test's rows. The grid's `filter` param searches name,
     * so it doubles as the filter_params value every case rides in on.
     */
    private static string $marker = '';

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * Seed n clients whose names all carry a fresh unique marker, and return them.
     *
     * @param int $count
     * @param int|null $priority Defaults to PRIORITY_MEDIUM - resolved in the BODY, because a
     *                           class constant in a parameter default is resolved by reflection
     *                           during the manifest scan, before the autoloader can answer.
     * @return Client_Model[]
     */
    private static function __seed(int $count, ?int $priority = null): array
    {
        $priority = $priority ?? Client_Model::PRIORITY_MEDIUM;

        static::$marker = 'MassAction' . str_replace('.', '', uniqid('', true));

        $clients = [];

        for ($i = 1; $i <= $count; $i++) {
            $client = new Client_Model();
            $client->name = static::$marker . ' Client ' . $i;
            $client->priority = $priority;
            $client->save();

            $clients[] = $client;
        }

        return $clients;
    }

    /**
     * filter_params in the shape the grid sends them: the free-text search plus whatever
     * quick filters were set.
     *
     * @param array $extra
     * @return array
     */
    private static function __filter_params(array $extra = []): array
    {
        return array_merge([
            'filter' => static::$marker,
            'sort' => 'id',
            'order' => 'desc',
        ], $extra);
    }

    /**
     * Live (non-deleted) ids among this test's seeded rows.
     *
     * @return int[]
     */
    private static function __surviving_ids(): array
    {
        return Client_Model::where('name', 'LIKE', static::$marker . '%')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // =====================================================================
    // bulk_delete
    // =====================================================================

    public static function test_additive_deletes_exactly_the_named_ids()
    {
        $clients = static::__seed(5);

        $doomed = [(int) $clients[0]->id, (int) $clients[3]->id];

        $result = Frontend_Clients_Controller::bulk_delete(request(), [
            'mode' => 'additive',
            'ids' => $doomed,
            'filter_params' => static::__filter_params(),
        ]);

        static::__assert_equals(2, $result['deleted'], 'additive deletes exactly the two named rows');

        $survivors = static::__surviving_ids();

        static::__assert_count(3, $survivors, 'the other three are untouched');

        foreach ($doomed as $id) {
            static::__assert_false(in_array($id, $survivors, true), "client {$id} is gone");
        }
    }

    public static function test_additive_with_no_ids_deletes_nothing()
    {
        static::__seed(3);

        $result = Frontend_Clients_Controller::bulk_delete(request(), [
            'mode' => 'additive',
            'ids' => [],
            'filter_params' => static::__filter_params(),
        ]);

        static::__assert_equals(0, $result['deleted'], 'an empty additive selection selects NOTHING, not everything');
        static::__assert_count(3, static::__surviving_ids());
    }

    public static function test_subtractive_deletes_everything_but_the_named_ids()
    {
        $clients = static::__seed(5);

        $spared = [(int) $clients[1]->id, (int) $clients[2]->id];

        $result = Frontend_Clients_Controller::bulk_delete(request(), [
            'mode' => 'subtractive',
            'ids' => $spared,
            'filter_params' => static::__filter_params(),
        ]);

        static::__assert_equals(3, $result['deleted'], 'subtractive deletes the filtered set minus the exclusions');

        $survivors = static::__surviving_ids();

        static::__assert_count(2, $survivors);
        static::__assert_equals($spared, $survivors, 'exactly the excluded rows survive');
    }

    public static function test_all_deletes_everything_matching_the_filter_params()
    {
        $clients = static::__seed(4, Client_Model::PRIORITY_URGENT);

        // One row moved out of the filtered view - the mass action must not reach it.
        $clients[0]->priority = Client_Model::PRIORITY_LOW;
        $clients[0]->save();

        $result = Frontend_Clients_Controller::bulk_delete(request(), [
            'mode' => 'all',
            'ids' => [],
            'filter_params' => static::__filter_params(['priority' => Client_Model::PRIORITY_URGENT]),
        ]);

        static::__assert_equals(3, $result['deleted'], 'only the rows the quick filter matched are deleted');

        $survivors = static::__surviving_ids();

        static::__assert_count(1, $survivors);
        static::__assert_equals((int) $clients[0]->id, $survivors[0], 'the row outside the filter survives');
    }

    public static function test_unknown_mode_is_rejected()
    {
        static::__seed(2);

        $result = Frontend_Clients_Controller::bulk_delete(request(), [
            'mode' => 'everything',
            'ids' => [],
            'filter_params' => static::__filter_params(),
        ]);

        static::__assert_instance_of(
            \App\RSpade\Core\Response\Error_Response::class,
            $result,
            'an unrecognized mode is a validation error, never a delete'
        );

        static::__assert_count(2, static::__surviving_ids(), 'nothing was deleted');
    }

    public static function test_non_integer_ids_are_rejected()
    {
        static::__seed(2);

        $result = Frontend_Clients_Controller::bulk_delete(request(), [
            'mode' => 'additive',
            'ids' => ['1; DROP TABLE clients'],
            'filter_params' => static::__filter_params(),
        ]);

        static::__assert_instance_of(
            \App\RSpade\Core\Response\Error_Response::class,
            $result,
            'a non-integer id is a validation error'
        );

        static::__assert_count(2, static::__surviving_ids(), 'nothing was deleted');
    }

    // =====================================================================
    // export_csv
    // =====================================================================

    public static function test_export_honours_selection_and_filter_params()
    {
        $clients = static::__seed(5);

        $wanted = [(int) $clients[0]->id, (int) $clients[2]->id, (int) $clients[4]->id];

        $result = Frontend_Clients_Controller::export_csv(request(), [
            'mode' => 'additive',
            'ids' => $wanted,
            'filter_params' => static::__filter_params(),
        ]);

        static::__assert_equals(3, $result['count'], 'the export covers exactly the selected rows');

        $rows = static::__parse_csv($result['csv']);

        // Header plus one row per selected client.
        static::__assert_count(4, $rows, 'CSV parses as a header plus three data rows');
        static::__assert_equals('ID', $rows[0][0], 'first column is the id');

        $exported_ids = [];

        foreach (array_slice($rows, 1) as $row) {
            $exported_ids[] = (int) $row[0];
        }

        sort($exported_ids);
        sort($wanted);

        static::__assert_equals($wanted, $exported_ids, 'the CSV carries exactly the selected ids');
    }

    public static function test_export_all_is_scoped_by_filter_params()
    {
        static::__seed(4, Client_Model::PRIORITY_URGENT);

        $result = Frontend_Clients_Controller::export_csv(request(), [
            'mode' => 'all',
            'ids' => [],
            'filter_params' => static::__filter_params(['priority' => Client_Model::PRIORITY_LOW]),
        ]);

        static::__assert_equals(0, $result['count'], 'a quick filter matching nothing exports nothing');
    }

    public static function test_export_quotes_fields_that_need_it()
    {
        static::$marker = 'MassActionCsv' . str_replace('.', '', uniqid('', true));

        $client = new Client_Model();
        $client->name = static::$marker . ' "Quote", Comma & ' . "\n" . 'Newline';
        $client->priority = Client_Model::PRIORITY_MEDIUM;
        $client->save();

        $result = Frontend_Clients_Controller::export_csv(request(), [
            'mode' => 'additive',
            'ids' => [(int) $client->id],
            'filter_params' => ['filter' => static::$marker],
        ]);

        $rows = static::__parse_csv($result['csv']);

        static::__assert_count(2, $rows, 'the embedded comma and newline did not split the row');
        static::__assert_equals($client->name, $rows[1][1], 'the name round-trips through CSV escaping intact');
    }

    public static function test_export_filename_carries_todays_date()
    {
        static::__seed(1);

        $result = Frontend_Clients_Controller::export_csv(request(), [
            'mode' => 'all',
            'ids' => [],
            'filter_params' => static::__filter_params(),
        ]);

        static::__assert_equals(
            'clients_export_' . \App\RSpade\Core\Time\Rsx_Date::today() . '.csv',
            $result['filename']
        );
    }

    /**
     * Parse a CSV string back into rows, so an assertion tests what a spreadsheet would see
     * rather than the bytes we happened to write.
     *
     * @param string $csv
     * @return array
     */
    private static function __parse_csv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
