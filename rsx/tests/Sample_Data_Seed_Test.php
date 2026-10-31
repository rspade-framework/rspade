<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Mail\Rsx_Test_Recipients;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Demo_Product_Model;
use Rsx\Models\Party_Model;
use Rsx\Models\Portal_Request_Thread_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * Sample_Data_Service::seed - the application's sample dataset (rsx_app:seed, and the
 * first-run screen's "Add sample data" option).
 *
 * Seeds the test baseline's site 1, which holds no application data, and checks what an
 * operator would look for: every record kind in the counts sample_data.json declares, a
 * document of every preview type, every contact reachable only on a TEST address and
 * number, the run attributed to the site's first user, and the activity log written. A
 * second run refuses - the seeder fills an empty site and never merges.
 */
class Sample_Data_Seed_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** sample_data.json, the source the expected counts are derived from. */
    private static function __data(): array
    {
        return json_decode((string) file_get_contents(base_path('rsx/services/sample_data/resource/sample_data.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Project and task counts the data declares, subprojects and subtasks included. */
    private static function __expected(array $data): array
    {
        $projects = 0;
        $tasks = count($data['general_tasks']);
        $documents = count($data['reference_documents']);
        $count_tasks = function (array $list) use (&$count_tasks, &$tasks, &$documents) {
            foreach ($list as $task) {
                $tasks++;
                $documents += isset($task['attach']) ? 1 : 0;
                $count_tasks($task['subtasks'] ?? []);
            }
        };
        $count_projects = function (array $list) use (&$count_projects, &$projects, $count_tasks) {
            foreach ($list as $project) {
                $projects++;
                $count_tasks($project['tasks']);
                $count_projects($project['subprojects'] ?? []);
            }
        };
        foreach ($data['clients'] as $client) {
            $count_projects($client['projects']);
            $count_tasks($client['client_tasks'] ?? []);
            $documents += count($client['documents']);
        }

        return ['projects' => $projects, 'tasks' => $tasks, 'documents' => $documents];
    }

    public static function test_an_empty_site_is_seeded_with_every_kind_of_record()
    {
        $data = static::__data();
        $expected = static::__expected($data);

        $products_before = Demo_Product_Model::count();

        $run = Task::internal('Sample_Data_Service', 'seed', ['site' => self::SITE_ID]);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the seed run completed: ' . $run->summary());

        static::__acting_as_site(self::SITE_ID);

        static::__assert_equals(count($data['clients']), Client_Model::count(), 'clients');
        static::__assert_equals(array_sum(array_map(fn ($c) => count($c['contacts']), $data['clients'])), Contact_Model::count(), 'contacts');
        static::__assert_equals($expected['projects'], Project_Model::count(), 'projects, subprojects included');
        static::__assert_equals($expected['tasks'], Task_Model::count(), 'tasks, subtasks and unattached tasks included');
        static::__assert_equals(count($data['parties']), Party_Model::count(), 'parties');
        static::__assert_true(Portal_Request_Thread_Model::count() > 0, 'request threads');
        static::__assert_equals(
            $products_before === 0 ? count($data['products']) : $products_before,
            Demo_Product_Model::count(),
            'products: the whole catalogue into an empty one, an existing one left alone'
        );

        $documents = File_Attachment_Model::whereIn('fileable_type', ['Client_Model', 'Task_Model'])->get();
        static::__assert_equals($expected['documents'], $documents->count(), 'documents on clients and tasks');
        $extensions = array_unique($documents->map(fn ($a) => strtolower(pathinfo($a->file_name, PATHINFO_EXTENSION)))->all());
        foreach (['pdf', 'docx', 'xlsx', 'png', 'jpg'] as $type) {
            static::__assert_true(in_array($type, $extensions, true), "a .{$type} document is seeded");
        }

        foreach (Contact_Model::all() as $contact) {
            static::__assert_true(Rsx_Test_Recipients::is_test_email($contact->email), "{$contact->email} is a test address");
            foreach (['phone_work', 'phone_cell'] as $field) {
                if ($contact->$field !== null) {
                    static::__assert_true(Rsx_Test_Recipients::is_test_phone($contact->$field), "{$contact->$field} is a test number");
                }
            }
        }

        $client = Client_Model::orderBy('id')->first();
        static::__assert_equals('User_Model', $client->created_by_type, 'records are attributed to the site\'s first user');
        static::__assert_true(Action_Log_Model::where('type_id', Action_Log_Model::TYPE_CLIENT_CREATED)->count() === count($data['clients']), 'the activity log records each client');
    }

    public static function test_a_site_that_holds_data_is_refused_and_left_untouched()
    {
        static::__acting_as_site(self::SITE_ID);
        $client = new Client_Model();
        $client->site_id = self::SITE_ID;
        $client->name = 'Existing Client';
        $client->save();

        $run = Task::internal('Sample_Data_Service', 'seed', ['site' => self::SITE_ID]);

        static::__acting_as_site(self::SITE_ID);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id, 'the run refuses');
        $stderr = implode("\n", array_column($run->output_after(null, ['stderr']), 'line'));
        static::__assert_contains('already has data', $stderr);
        static::__assert_equals(1, Client_Model::count(), 'nothing was added');
    }
}
