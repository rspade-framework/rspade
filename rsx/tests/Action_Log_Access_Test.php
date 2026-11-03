<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Permission_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Dashboard\Frontend_Dashboard_Controller;
use Rsx\App\Frontend\Revisions\Frontend_Revisions_Controller;
use Rsx\Models\Client_Model;

/**
 * Who reads the action log, and who reads a record's change history.
 *
 * The action log is for administrators (Site Admin and up; a Manager is refused): the Action Log screens, every per-record Activity
 * feed (`*_activity`) and the dashboard's recent-activity feed answer only a holder of
 * can_view_user_activity. A record's revision history is wider - every staff member who may
 * read the record, a User or a Viewer included. And the dashboard page asks exactly what its
 * data call asks, so a user refused the data is refused the page rather than shown an error.
 */
class Action_Log_Access_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * Every surface that returns action-log rows.
     */
    private const ACTION_LOG_SURFACES = [
        'Action_Logs_Index_Action',
        'Action_Logs_View_Action',
        'Frontend_Action_Logs_Controller::datagrid_fetch',
        'Frontend_Clients_Controller::client_activity',
        'Frontend_Contacts_Controller::contact_activity',
        'Frontend_Projects_Controller::project_activity',
        'Frontend_Tasks_Controller::task_activity',
        'Frontend_Party_Controller::party_activity',
    ];

    /**
     * The revision history: the endpoint and the three pages showing it.
     */
    private const HISTORY_SURFACES = [
        'Frontend_Revisions_Controller::history',
        'Clients_History_Action',
        'Contacts_History_Action',
        'Tasks_History_Action',
    ];

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __member(int $role_id): User_Model
    {
        $email = 'action_log_access_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = 'not-a-login-path';
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Action';
        $user->last_name = 'Log';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->invite_accepted_at = now();
        $user->save();

        return $user;
    }

    private static function __act_as(User_Model $user): void
    {
        static::__reset_session();
        static::__acting_as_user((int) $user->id);
    }

    private static function __passes(string $target): bool
    {
        return Auth_Gates::gates_pass(Auth_Gates::surface_gates($target), 'staff');
    }

    public static function test_every_action_log_surface_names_the_administrator_check()
    {
        foreach (self::ACTION_LOG_SURFACES as $target) {
            static::__assert_true(
                in_array('can_view_user_activity', Auth_Gates::surface_gates($target), true),
                "{$target} is gated on can_view_user_activity"
            );
        }
    }

    public static function test_below_site_admin_is_refused_the_action_log_and_reads_change_history()
    {
        foreach ([User_Model::ROLE_MANAGER, User_Model::ROLE_USER, User_Model::ROLE_VIEWER] as $role_id) {
            static::__act_as(static::__member($role_id));

            foreach (self::ACTION_LOG_SURFACES as $target) {
                static::__assert_false(static::__passes($target), "role {$role_id} is refused {$target}");
            }

            foreach (self::HISTORY_SURFACES as $target) {
                static::__assert_true(static::__passes($target), "role {$role_id} may open {$target}");
            }
        }
    }

    public static function test_an_administrator_reads_the_action_log()
    {
        foreach ([User_Model::ROLE_SITE_ADMIN, User_Model::ROLE_SITE_OWNER] as $role_id) {
            static::__act_as(static::__member($role_id));

            foreach (self::ACTION_LOG_SURFACES as $target) {
                static::__assert_true(static::__passes($target), "role {$role_id} may open {$target}");
            }
        }
    }

    public static function test_a_plain_user_reads_a_records_revision_history()
    {
        static::__act_as(static::__member(User_Model::ROLE_VIEWER));

        $client = new Client_Model();
        $client->name = 'ActionLogAccess ' . str_replace('.', '', uniqid('', true));
        $client->save();
        $client->name = $client->name . ' Renamed';
        $client->save();

        $result = Frontend_Revisions_Controller::history(new Request(), [
            'record_type' => 'Client_Model',
            'record_id' => (int) $client->id,
        ]);

        static::__assert_true(isset($result['transactions']), 'the history is returned to a Viewer');
        static::__assert_true(count($result['transactions']) > 0, 'with the edit in it');
    }

    public static function test_the_dashboard_feed_is_empty_for_a_plain_user()
    {
        static::__act_as(static::__member(User_Model::ROLE_USER));

        $data = Frontend_Dashboard_Controller::dashboard_data(new Request(), []);

        static::__assert_equals([], $data['recent_activity'], 'no action-log rows on a plain user\'s dashboard');
    }

    public static function test_the_dashboard_page_asks_what_its_data_asks()
    {
        $user = static::__member(User_Model::ROLE_USER);
        static::__act_as($user);
        static::__assert_true(static::__passes('Dashboard_Index_Action'), 'a user who reads data opens the dashboard');
        static::__assert_true(static::__passes('Frontend_Dashboard_Controller::dashboard_data'), 'and loads its data');

        User_Permission_Model::deny((int) $user->id, User_Model::PERM_VIEW_DATA);
        static::__act_as($user);
        static::__assert_false(static::__passes('Frontend_Dashboard_Controller::dashboard_data'), 'a DENY on view-data refuses the data');
        static::__assert_false(static::__passes('Dashboard_Index_Action'), 'and the page with it');
    }
}
