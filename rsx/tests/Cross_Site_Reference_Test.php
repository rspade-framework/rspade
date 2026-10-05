<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Contacts\Frontend_Contacts_Controller;
use Rsx\App\Frontend\Projects\Frontend_Projects_Controller;
use Rsx\App\Frontend\Tasks\Frontend_Tasks_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Task_Model;

/**
 * A save never stores another site's id, and a grid never prints another site's row.
 *
 * Every id a staff save accepts (client_id, project_id, parent_project_id, contact and user
 * pivot ids, assigned_to_user_id) is looked up through its SITE-SCOPED model, so an id of
 * another tenant misses exactly like one that does not exist and the save answers a field
 * error. The list grids join their related tables on the base row's own site_id, so a row
 * that somehow carries a foreign id (raw SQL, an import) shows nothing of the other site.
 */
class Cross_Site_Reference_Test extends Rsx_Test_Abstract
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

    /**
     * A second tenant for the duration of one test (rolled back with it).
     */
    private static function __other_site(): int
    {
        $site = new Site_Model();
        $site->slug = 'cross-site-' . uniqid();
        $site->name = 'Cross-site probe tenant';
        $site->save();

        return (int) $site->id;
    }

    private static function __client_on_site(int $site_id): Client_Model
    {
        static::__acting_as_site($site_id);
        $client = new Client_Model();
        $client->name = 'Cross-site probe ' . uniqid();
        $client->save();
        static::__acting_as_site(self::SITE_ID);

        return $client;
    }

    private static function __user_on_site(int $site_id): User_Model
    {
        static::__acting_as_site($site_id);
        $user = new User_Model();
        $user->site_id = $site_id;
        $user->email = 'cross_site_' . uniqid() . '@example.com';
        $user->first_name = 'Foreign';
        $user->last_name = 'Person';
        $user->role_id = User_Model::ROLE_USER;
        $user->is_enabled = true;
        $user->save();
        static::__acting_as_site(self::SITE_ID);

        return $user;
    }

    private static function __assert_field_error($result, string $field): void
    {
        static::__assert_instance_of(Error_Response::class, $result, "{$field}: the save is refused");
        static::__assert_array_has_key($field, $result->get_metadata(), "{$field}: the error names the field");
    }

    public static function test_a_contact_cannot_join_another_sites_client()
    {
        $foreign = static::__client_on_site(static::__other_site());

        $result = Frontend_Contacts_Controller::save(new Request(), [
            'first_name' => 'Cross',
            'last_name' => 'Site',
            'email' => 'cross_contact_' . uniqid() . '@example.com',
            'client_id' => $foreign->id,
        ]);

        static::__assert_field_error($result, 'client_id');
    }

    public static function test_a_project_cannot_reference_another_sites_records()
    {
        $own = static::__client_on_site(self::SITE_ID);
        $foreign_client = static::__client_on_site(static::__other_site());
        $foreign_user = static::__user_on_site(static::__other_site());

        static::__assert_field_error(
            Frontend_Projects_Controller::save(new Request(), ['name' => 'P', 'client_id' => $foreign_client->id]),
            'client_id'
        );
        static::__assert_field_error(
            Frontend_Projects_Controller::save(new Request(), [
                'name' => 'P',
                'client_id' => $own->id,
                'assigned_users' => [$foreign_user->id],
            ]),
            'assigned_users'
        );
    }

    public static function test_a_task_cannot_be_assigned_to_another_sites_user()
    {
        $foreign_user = static::__user_on_site(static::__other_site());

        static::__assert_field_error(
            Frontend_Tasks_Controller::save(new Request(), [
                'title' => 'Cross-site task',
                'assigned_to_user_id' => $foreign_user->id,
            ]),
            'assigned_to_user_id'
        );
    }

    public static function test_the_tasks_grid_prints_no_foreign_assignee()
    {
        $foreign_user = static::__user_on_site(static::__other_site());

        $task = new Task_Model();
        $task->title = 'Grid probe ' . uniqid();
        $task->save();

        // A foreign id that reached the row without the save's validation.
        DB::table('tasks')->where('id', $task->id)->update(['assigned_to_user_id' => $foreign_user->id]);

        $grid = Frontend_Tasks_Controller::datagrid_fetch(new Request(), ['per_page' => 500, 'filter' => $task->title]);

        $row = null;
        foreach ($grid['records'] as $record) {
            if ((int) $record['id'] === (int) $task->id) {
                $row = $record;
            }
        }

        static::__assert_not_null($row, 'the task is listed');
        static::__assert_null($row['assigned_to_name'], "another site's user is not printed");
    }
}
