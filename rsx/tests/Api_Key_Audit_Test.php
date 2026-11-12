<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Messaging\Mail\Email_Queue_Model;
use App\RSpade\Lib\Messaging\Mail\Rsx_Mail;
use App\RSpade\Lib\SignIn\Confirmation\Rsx_Password_Confirmation;
use Rsx\App\Frontend\Settings\ApiKeys\Frontend_Settings_Api_Keys_Controller;
use Rsx\App\Frontend\Settings\UserManagement\Frontend_Settings_User_Management_Controller;
use Rsx\Emails\Api_Key_Notice_Email;
use Rsx\Models\Action_Log_Model;

/**
 * EVERY API KEY CREATED OR ENDED ON AN ACCOUNT IS ONE AUDIT ROW AND ONE SECURITY NOTICE TO ITS
 * HOLDER - except a key rsx:api:key:temp mints for a script run.
 *
 * Each path is driven through the code a person uses (the Settings screen, User Management, the
 * commands) and followed to its end: the framework's api_key.created / api_key.revoked reaches
 * Api_Key_Event_Handlers, which queues ONE Api_Key_Audit_Service::record_api_key_event run; the
 * test runs that queued run itself (Task::dispatch() spawns no worker under the suite) and
 * reads the action log and the mail queue.
 *
 * The key events fire after a COMMIT, which the per-test transaction never makes, so the class
 * commits and starts from a clean baseline. A signed-in path acts as the harness's signed-in
 * browser (__as_signed_in_browser()), whose audit session records ADDRESS; a command has none.
 */
class Api_Key_Audit_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;
    protected static $acts_as_browser = true;

    private const SITE_ID = 1;

    /** The client address of the harness's signed-in browser (__as_signed_in_browser()). */
    private const ADDRESS = '10.0.0.9';

    public static function setup(): void
    {
        // Minting sits behind a password confirmation, which is not this class's subject.
        Rsx_Password_Confirmation::$force_confirmed_for_tests = true;
    }

    public static function teardown(): void
    {
        Rsx_Password_Confirmation::$force_confirmed_for_tests = null;
        Session::logout();
        static::__reset_session();
    }

    /**
     * A signed-up member of the site with API access on. Leaves the session anonymous.
     */
    private static function __member(int $role_id = User_Model::ROLE_USER): User_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $login_user = new Login_User_Model();
        $login_user->email = 'api_key_audit_' . uniqid() . '@example.com';
        $login_user->set_password('the-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = $login_user->id;
        $user->email = $login_user->email;
        $user->first_name = 'Key';
        $user->last_name = 'Holder';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->is_api_access_enabled = true;
        $user->invite_accepted_at = now();
        $user->save();

        Session::logout();
        static::__reset_session();

        return $user;
    }

    private static function __last_run_id(): int
    {
        return (int) Task_Run_Model::max('id');
    }

    /**
     * Run every record_api_key_event queued after $after (as a worker would) and answer their
     * params, so a test asserts how many the action produced.
     */
    private static function __run_queued(int $after): array
    {
        $runs = Task_Run_Model::where('id', '>', $after)
            ->where('method', 'record_api_key_event')
            ->orderBy('id')
            ->get();

        $params = [];
        foreach ($runs as $run) {
            $params[] = $run->params;
            $settled = static::__run_pending_task((int) $run->id);
            static::__assert_null($settled->error, 'the audit run succeeded: ' . (string) $settled->error);
        }

        return $params;
    }

    /**
     * The API key rows about $user, oldest first.
     */
    private static function __rows(User_Model $user): array
    {
        Session::set_temporary_site_id(self::SITE_ID);
        try {
            return Action_Log_Model::where('subject_type', 'User_Model')
                ->where('subject_id', $user->id)
                ->whereIn('type_id', [Action_Log_Model::TYPE_API_KEY_CREATED, Action_Log_Model::TYPE_API_KEY_REVOKED])
                ->orderBy('id')
                ->get()
                ->all();
        } finally {
            Session::clear_temporary_site_id();
        }
    }

    /**
     * The notices queued to $email, oldest first.
     */
    private static function __notices_to(string $email): array
    {
        return Email_Queue_Model::without_site_scope(
            fn () => Email_Queue_Model::where('to_address', $email)->where('email_class', 'Api_Key_Notice_Email')->orderBy('id')->get()->all()
        );
    }

    /**
     * One more row and one more notice than before, naming the actor and the address.
     */
    private static function __assert_recorded(User_Model $user, int $rows_before, int $type, ?int $actor_login_user_id, ?string $address, string $phrase): Action_Log_Model
    {
        $rows = static::__rows($user);
        static::__assert_count($rows_before + 1, $rows, 'exactly one more API key row');
        $row = end($rows);

        static::__assert_equals($type, (int) $row->type_id);
        static::__assert_equals($actor_login_user_id, $row->actor_id === null ? null : (int) $row->actor_id, 'the actor');
        static::__assert_equals($address, $row->audit_ip_address(), "the row's address is the event's audit session's");
        static::__assert_contains($phrase, $row->render(), 'the line names who did it');
        if ($address !== null) {
            static::__assert_contains(' from ' . $address, $row->render(), 'the line prints the address');
        }

        static::__assert_count($rows_before + 1, static::__notices_to($user->email), 'exactly one more notice to the holder');

        return $row;
    }

    private static function __mint_on_screen(User_Model $user): int
    {
        static::__as_signed_in_browser((int) $user->id);

        $result = Frontend_Settings_Api_Keys_Controller::create_key(new Request(), [
            'name' => 'Nightly export',
            'access_mode' => 'scoped',
            'scopes' => '/api/v1/me',
            'read_only' => '1',
        ]);
        static::__assert_true(isset($result['id']), 'the key was minted: ' . json_encode($result));

        return (int) $result['id'];
    }

    /**
     * aka-01: Settings > API Keys - the holder mints and revokes their own key; each is a row
     * naming them, with the address, and a notice; the creation notice states the access.
     */
    public static function test_minting_and_revoking_on_the_settings_screen()
    {
        $user = static::__member();
        $after = static::__last_run_id();

        $key_id = static::__mint_on_screen($user);

        static::__assert_count(1, static::__run_queued($after), 'one audit run');
        $row = static::__assert_recorded($user, 0, Action_Log_Model::TYPE_API_KEY_CREATED, (int) $user->login_user_id, self::ADDRESS, 'created API key "Nightly export"');
        static::__assert_equals($key_id, $row->get_metadata()['api_key_id']);
        static::__assert_true($row->get_metadata()['read_only'], 'the access is recorded');

        $notice = static::__notices_to($user->email)[0];
        static::__assert_equals('created', $notice->tag);
        static::__assert_equals(Email_Queue_Model::CATEGORY_SECURITY, (int) $notice->category_id);

        $after = static::__last_run_id();
        Frontend_Settings_Api_Keys_Controller::revoke_key(new Request(), ['id' => $key_id]);

        static::__assert_count(1, static::__run_queued($after), 'one audit run');
        static::__assert_recorded($user, 1, Action_Log_Model::TYPE_API_KEY_REVOKED, (int) $user->login_user_id, self::ADDRESS, 'revoked their API key "Nightly export"');
        static::__assert_equals('revoked', static::__notices_to($user->email)[1]->tag);
    }

    /**
     * aka-02: an administrator revokes another user's key from User Management - the row names
     * the administrator, is on both histories, and the holder is told.
     */
    public static function test_an_administrator_revocation()
    {
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN);
        $holder = static::__member();
        $key = Api_Key_Model::generate((int) $holder->id, 'Integration')['model'];
        static::__run_queued(static::__last_run_id() - 1);
        $rows_before = count(static::__rows($holder));

        static::__as_signed_in_browser((int) $admin->id);
        $after = static::__last_run_id();

        $result = Frontend_Settings_User_Management_Controller::revoke_user_api_key(new Request(), ['id' => $holder->id, 'key_id' => $key->id]);
        static::__assert_true($result['revoked'] ?? false, 'revoked: ' . json_encode($result));

        static::__assert_count(1, static::__run_queued($after), 'one audit run');
        $row = static::__assert_recorded($holder, $rows_before, Action_Log_Model::TYPE_API_KEY_REVOKED, (int) $admin->login_user_id, self::ADDRESS, $admin->email . ' revoked API key "Integration"');

        $holder_history = Frontend_Settings_User_Management_Controller::get_user_activity(new Request(), ['id' => $holder->id]);
        $admin_history = Frontend_Settings_User_Management_Controller::get_user_activity(new Request(), ['id' => $admin->id]);
        static::__assert_true(in_array((int) $row->id, array_column($holder_history['activity'], 'id')), "on the holder's history");
        static::__assert_true(in_array((int) $row->id, array_column($admin_history['activity'], 'id')), "on the administrator's history");
    }

    /**
     * aka-03: the command line - create, revoke and purge each record a row with no actor and
     * no address, and tell the holder; deleting an already revoked key records nothing.
     */
    public static function test_the_command_line()
    {
        $user = static::__member();
        static::__acting_as_site(self::SITE_ID);   // reading the rows back renders their subject
        $after = static::__last_run_id();

        Artisan::call('rsx:api:key:create', ['--user' => (string) $user->id, '--name' => 'Provisioned', '--json' => true]);
        $key_id = (int) json_decode(Artisan::output(), true)['data']['api_key']['id'];
        static::__assert_count(1, static::__run_queued($after));
        static::__assert_recorded($user, 0, Action_Log_Model::TYPE_API_KEY_CREATED, null, null, 'was created for');

        $after = static::__last_run_id();
        Artisan::call('rsx:api:key:delete', ['id' => (string) $key_id, '--force' => true, '--json' => true]);
        static::__assert_count(1, static::__run_queued($after));
        static::__assert_recorded($user, 1, Action_Log_Model::TYPE_API_KEY_REVOKED, null, null, 'was revoked from the command line');

        $after = static::__last_run_id();
        Artisan::call('rsx:api:key:delete', ['id' => (string) $key_id, '--purge' => true, '--force' => true, '--json' => true]);
        static::__assert_count(0, static::__run_queued($after), 'purging a revoked key is not news');

        $after = static::__last_run_id();
        Artisan::call('rsx:api:key:create', ['--user' => (string) $user->id, '--name' => 'Created in error', '--json' => true]);
        $live_id = (int) json_decode(Artisan::output(), true)['data']['api_key']['id'];
        Artisan::call('rsx:api:key:delete', ['id' => (string) $live_id, '--purge' => true, '--force' => true, '--json' => true]);
        static::__assert_count(2, static::__run_queued($after), 'the mint, and the purge of the live key');
        $rows = static::__rows($user);
        static::__assert_equals('deleted', end($rows)->get_metadata()['how']);
        static::__assert_contains('was deleted from the command line', end($rows)->render());
        $notices = static::__notices_to($user->email);
        static::__assert_count(4, $notices, 'one notice per recorded event');
        static::__assert_equals('deleted', end($notices)->tag);
    }

    /**
     * aka-04: rsx:api:key:temp records nothing and mails nothing, minted or revoked.
     */
    public static function test_a_temporary_key_is_skipped()
    {
        $user = static::__member();
        $after = static::__last_run_id();

        Artisan::call('rsx:api:key:temp', ['--user' => (string) $user->id, '--json' => true]);
        $key_id = (int) json_decode(Artisan::output(), true)['data']['api_key']['id'];
        Artisan::call('rsx:api:key:delete', ['id' => (string) $key_id, '--force' => true, '--json' => true]);

        static::__assert_count(0, static::__run_queued($after), 'no audit run');
        static::__assert_count(0, static::__rows($user));
        static::__assert_count(0, static::__notices_to($user->email));
    }

    /**
     * aka-05: a rolled-back mint queues nothing.
     */
    public static function test_a_rolled_back_mint_sends_nothing()
    {
        $user = static::__member();
        $after = static::__last_run_id();

        DB::beginTransaction();
        Api_Key_Model::generate((int) $user->id, 'Never committed');
        DB::rollBack();

        static::__assert_count(0, static::__run_queued($after));
        static::__assert_count(0, static::__notices_to($user->email));
    }

    /**
     * aka-06: SECURITY - the notice reaches an address that opted out of everything and one on
     * the site block list (a warning about the recipient's own account; rsx:man email).
     */
    public static function test_the_notice_ignores_the_opt_out_and_the_block_list()
    {
        $user = static::__member();

        Session::set_temporary_site_id(self::SITE_ID);
        try {
            Site_Lock::write(self::SITE_ID, function () use ($user) {
                Rsx_Mail::block_all($user->email);
                Rsx_Mail::block_address($user->email, 'Do not email');
            });
        } finally {
            Session::clear_temporary_site_id();
        }

        $after = static::__last_run_id();
        Api_Key_Model::generate((int) $user->id, 'Despite the blocks');
        static::__assert_count(1, static::__run_queued($after));

        $notices = static::__notices_to($user->email);
        static::__assert_count(1, $notices);
        static::__assert_not_equals(Email_Queue_Model::STATUS_BLOCKED, (int) $notices[0]->status_id, 'not blocked');
    }

    /**
     * aka-07: the notice carries the line a security notice needs and no way to turn it off.
     */
    public static function test_the_notice_has_the_line_and_no_preferences_footer()
    {
        $preview = Rsx_Mail::preview(Api_Key_Notice_Email::sample());
        $html = html_entity_decode((string) $preview['html'], ENT_QUOTES);

        static::__assert_equals(Api_Key_Notice_Email::SECURITY, Api_Key_Notice_Email::CATEGORY);
        static::__assert_contains("If this wasn't you, contact an administrator.", $html);
        static::__assert_contains('Nightly export', $html, 'names the key');
        static::__assert_contains('203.0.113.7', $html, 'from which address');
        static::__assert_contains('Read-only', $html, 'the access of a created key');
        static::__assert_contains('/api/v1/contacts/*', $html, 'its scopes');
        static::__assert_false(stripos($html, 'unsubscribe') !== false, 'no unsubscribe link');
        static::__assert_false(stripos($html, 'notification') !== false, 'no notification-preferences wording');
    }
}
