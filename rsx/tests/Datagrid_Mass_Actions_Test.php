<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\App\Frontend\Clients\List\Clients_DataGrid;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Client_Model;
use Rsx\Services\Mass_Edit_Service;

/**
 * Datagrid mass actions - selection resolution.
 *
 * The whole risk in a mass action is that the SET the server resolves is not the set the
 * user selected: the client sends a mode plus a short id list plus the filters that were
 * on screen, and the server rebuilds the query from those three things. These tests pin
 * all three shapes against the clients grid - bulk_delete's validation at the endpoint, and
 * the deletion itself in the task it dispatches (Mass_Edit_Service::delete_clients):
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
    // bulk_delete - a mass edit: the endpoint validates and dispatches,
    // Mass_Edit_Service::delete_clients resolves the selection and deletes
    // one record per write section.
    // =====================================================================

    /**
     * Run the mass delete the endpoint dispatches, in this process, as user 1 (the task is
     * #[Run_As_Dispatcher]; a dispatched run needs a browser sign-in a test does not have).
     * Answers the run's state report: {selected, deleted, skipped, refused}.
     *
     * @param array $payload The selection payload: mode, ids, filter_params
     * @return array
     */
    private static function __mass_delete(array $payload): array
    {
        static::__acting_as_user(1);

        return static::__run_task_method(Mass_Edit_Service::class, 'delete_clients', $payload);
    }

    public static function test_additive_deletes_exactly_the_named_ids()
    {
        $clients = static::__seed(5);

        $doomed = [(int) $clients[0]->id, (int) $clients[3]->id];

        $state = static::__mass_delete([
            'mode' => 'additive',
            'ids' => $doomed,
            'filter_params' => static::__filter_params(),
        ]);

        static::__assert_equals(2, $state['deleted'], 'additive deletes exactly the two named rows');
        static::__assert_equals(0, $state['skipped'], 'nothing changed under the run, so nothing is skipped');

        $survivors = static::__surviving_ids();

        static::__assert_count(3, $survivors, 'the other three are untouched');

        foreach ($doomed as $id) {
            static::__assert_false(in_array($id, $survivors, true), "client {$id} is gone");
        }
    }

    public static function test_additive_with_no_ids_deletes_nothing()
    {
        static::__seed(3);

        $state = static::__mass_delete([
            'mode' => 'additive',
            'ids' => [],
            'filter_params' => static::__filter_params(),
        ]);

        static::__assert_equals(0, $state['selected'], 'an empty additive selection selects NOTHING, not everything');
        static::__assert_count(3, static::__surviving_ids());
    }

    public static function test_subtractive_deletes_everything_but_the_named_ids()
    {
        $clients = static::__seed(5);

        $spared = [(int) $clients[1]->id, (int) $clients[2]->id];

        $state = static::__mass_delete([
            'mode' => 'subtractive',
            'ids' => $spared,
            'filter_params' => static::__filter_params(),
        ]);

        static::__assert_equals(3, $state['deleted'], 'subtractive deletes the filtered set minus the exclusions');

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

        $state = static::__mass_delete([
            'mode' => 'all',
            'ids' => [],
            'filter_params' => static::__filter_params(['priority' => Client_Model::PRIORITY_URGENT]),
        ]);

        static::__assert_equals(3, $state['deleted'], 'only the rows the quick filter matched are deleted');

        $survivors = static::__surviving_ids();

        static::__assert_count(1, $survivors);
        static::__assert_equals((int) $clients[0]->id, $survivors[0], 'the row outside the filter survives');
    }

    public static function test_each_deleted_record_is_logged_like_a_single_delete()
    {
        $clients = static::__seed(2);

        static::__mass_delete([
            'mode' => 'additive',
            'ids' => [(int) $clients[0]->id, (int) $clients[1]->id],
            'filter_params' => static::__filter_params(),
        ]);

        foreach ($clients as $client) {
            $logged = Action_Log_Model::where('type_id', Action_Log_Model::TYPE_CLIENT_DELETED)
                ->where('subject_id', $client->id)
                ->exists();
            static::__assert_true($logged, "client {$client->id} has its own deletion entry in the activity log");
        }
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
            'an unrecognized mode is a validation error, never a dispatch'
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

    public static function test_export_neutralises_formula_cells()
    {
        static::$marker = 'MassActionFormula' . str_replace('.', '', uniqid('', true));

        $client = new Client_Model();
        $client->name = static::$marker;
        $client->city = '=HYPERLINK("http://example.invalid","x")';
        $client->phone = '@SUM(1+1)';
        $client->email = '+cmd@example.com';
        $client->priority = Client_Model::PRIORITY_MEDIUM;
        $client->save();

        $result = Frontend_Clients_Controller::export_csv(request(), [
            'mode' => 'additive',
            'ids' => [(int) $client->id],
            'filter_params' => ['filter' => static::$marker],
        ]);

        $rows = static::__parse_csv($result['csv']);
        $cells = array_combine($rows[0], $rows[1]);

        static::__assert_equals("'=HYPERLINK(\"http://example.invalid\",\"x\")", $cells['City'], 'a leading = is prefixed with a quote');
        static::__assert_equals("'@SUM(1+1)", $cells['Phone'], 'a leading @ is prefixed');
        static::__assert_equals("'+cmd@example.com", $cells['Email'], 'a leading + is prefixed');
        static::__assert_equals(static::$marker, $cells['Company Name'], 'an ordinary cell is untouched');
    }

    public static function test_build_csv_neutralises_every_formula_lead_and_leaves_numbers()
    {
        $csv = Clients_DataGrid::build_csv(['A', 'B', 'C', 'D', 'E', 'F', 'G'], [
            ['-2+3', "\tTAB", "\rCR", '=1', -5, 12.5, 'plain'],
        ]);

        $rows = static::__parse_csv($csv);

        static::__assert_equals(["'-2+3", "'\tTAB", "'\rCR", "'=1", '-5', '12.5', 'plain'], $rows[1],
            'string cells starting - tab CR = are prefixed; numbers and plain text are not');
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

    // =====================================================================
    // export_xlsx
    // =====================================================================

    /**
     * Every xlsx cell is written by its type: text that begins with "=" stays text, never a
     * live formula, and the id stays a number.
     */
    public static function test_xlsx_export_writes_no_formula_cells()
    {
        static::$marker = 'MassActionXlsx' . str_replace('.', '', uniqid('', true));

        $client = new Client_Model();
        $client->name = '=HYPERLINK("http://example.invalid","x") ' . static::$marker;
        $client->city = '=1+1';
        $client->phone = '+15555550100';
        $client->priority = Client_Model::PRIORITY_MEDIUM;
        $client->save();

        $result = Frontend_Clients_Controller::export_xlsx(request(), [
            'mode' => 'additive',
            'ids' => [(int) $client->id],
            'filter_params' => ['filter' => static::$marker],
        ]);

        static::__assert_equals(1, $result['count'], 'the export covers the one selected row');

        $path = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        file_put_contents($path, base64_decode($result['xlsx_base64']));

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        unlink($path);

        $sheet = $spreadsheet->getActiveSheet();

        static::__assert_equals(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType(), 'the id is a number');
        static::__assert_equals((int) $client->id, (int) $sheet->getCell('A2')->getValue());

        static::__assert_equals(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType(), 'a name beginning = is text');
        static::__assert_equals($client->name, $sheet->getCell('B2')->getValue(), 'the name is stored verbatim');
        static::__assert_equals(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType(), 'a city beginning = is text');
        static::__assert_equals('=1+1', $sheet->getCell('C2')->getValue());
        static::__assert_equals(DataType::TYPE_STRING, $sheet->getCell('E2')->getDataType(), 'a phone is text');

        foreach (range('B', 'J') as $column) {
            static::__assert_true(
                $sheet->getCell($column . '2')->getDataType() !== DataType::TYPE_FORMULA,
                "column {$column} holds no formula"
            );
        }

        $spreadsheet->disconnectWorksheets();
    }

    // =====================================================================
    // Sorting and search (DataGrid_Abstract::fetch / Clients_DataGrid::build_query)
    // =====================================================================

    /**
     * A sort value that is not a whitelisted STRING falls back to the default - `true` would
     * pass a loose in_array and reach orderBy() - and a non-string order is the default
     * order rather than a TypeError.
     */
    public static function test_sort_whitelist_is_strict_and_order_is_validated()
    {
        static::__seed(2);

        $result = Clients_DataGrid::fetch(['sort' => true, 'order' => ['asc'], 'filter' => static::$marker]);
        static::__assert_equals('id', $result['sort'], 'a boolean sort falls back to the default');
        static::__assert_equals('desc', $result['order'], 'an array order falls back to the default');

        $result = Clients_DataGrid::fetch(['sort' => 'name', 'order' => 'ASC', 'filter' => static::$marker]);
        static::__assert_equals('name', $result['sort'], 'a whitelisted column is honoured');
        static::__assert_equals('asc', $result['order'], 'order is case-insensitive');

        $result = Clients_DataGrid::fetch(['sort' => 'deleted_at', 'filter' => static::$marker]);
        static::__assert_equals('id', $result['sort'], 'an unlisted column falls back to the default');
    }

    /**
     * A grid that declares no whitelist refuses a sort request loudly rather than handing
     * the requested column to orderBy().
     */
    public static function test_sort_request_on_a_grid_without_a_whitelist_is_refused()
    {
        $grid = new class extends Clients_DataGrid {
            protected static array $sortable_columns = [];
        };

        $result = $grid::fetch(['filter' => 'nothing-matches-' . uniqid()]);
        static::__assert_equals('id', $result['sort'], 'no sort requested: the default applies');

        static::__assert_throws(\RuntimeException::class, function () use ($grid) {
            $grid::fetch(['sort' => 'name']);
        }, 'declares no $sortable_columns');
    }

    /**
     * The free-text search reaches the street address the edit form writes.
     */
    public static function test_search_matches_the_street_address()
    {
        $street = 'MassActionStreet' . str_replace('.', '', uniqid('', true));

        $client = new Client_Model();
        $client->name = 'Street Search Client';
        $client->address_street = '12 ' . $street . ' Road';
        $client->priority = Client_Model::PRIORITY_MEDIUM;
        $client->save();

        $result = Clients_DataGrid::fetch(['filter' => $street]);

        static::__assert_equals(1, $result['total'], 'the street address is searchable');
        static::__assert_equals((int) $client->id, (int) $result['records'][0]['id']);
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
