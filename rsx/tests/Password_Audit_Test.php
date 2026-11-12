<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Auth\Password_Change;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Messaging\Mail\Email_Queue_Model;
use App\RSpade\Lib\Messaging\Mail\Rsx_Mail;
use App\RSpade\Lib\SignIn\Confirmation\Rsx_Password_Confirmation;
use App\RSpade\Lib\SignIn\Turnstile\Rsx_Turnstile;
use Rsx\App\Frontend\Settings\PasswordSecurity\Frontend_Settings_Password_Security_Controller;
use Rsx\App\Frontend\Settings\UserManagement\Frontend_Settings_User_Management_Controller;
use Rsx\App\Login\Login_Controller;
use Rsx\App\Login\PasswordReset\Password_Reset_Controller;
use Rsx\Emails\Password_Changed_Email;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Login_User_Password_Reset_Model;

/**
 * EVERY PASSWORD EVENT IS ONE AUDIT ROW, WITH ITS CAUSE, ACTOR AND ADDRESS - and the owner's own
 * changes are announced by a SECURITY email.
 *
 * Each path is driven through the code a person uses (the controllers, the command) and the
 * pipeline is followed to its end: the framework's login_user.password.changed (or the
 * application's login_user.password.reset_requested) reaches Password_Event_Handlers, which ends
 * the identity's open reset links and queues ONE Password_Audit_Service::record_password_event
 * run; the test then runs that queued run itself (Task::dispatch() spawns no worker under the
 * suite) and reads the action log and the mail queue.
 *
 * The password-change event fires after a COMMIT, which the per-test transaction never makes,
 * so the class commits and starts from a clean baseline. A signed-in path acts as the harness's
 * signed-in browser (__as_signed_in_browser()), whose audit session records ADDRESS, and its
 * row must print that address; a signed-OUT path (a reset link, the forced change) runs in the
 * test process's own virtual session, which has no client address, so its row carries none -
 * the browser check covers those addresses over real HTTP.
 */
class Password_Audit_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;
    protected static $acts_as_browser = true;

    private const SITE_ID = 1;

    private const PASSWORD = 'the-first-password';

    private const NEW_PASSWORD = 'the-second-password';

    /** The client address of the harness's signed-in browser (__as_signed_in_browser()). */
    private const ADDRESS = '10.0.0.9';

    public static function teardown(): void
    {
        Rsx_Turnstile::$force_verify_result_for_tests = null;
        Rsx_Password_Confirmation::$force_confirmed_for_tests = null;
        Session::logout();
        static::__reset_session();
    }

    /**
     * A signed-up member of $site_id (a new identity unless one is given). Leaves the session
     * anonymous.
     */
    private static function __member(int $role_id = User_Model::ROLE_USER, ?Login_User_Model $login_user = null, int $site_id = self::SITE_ID, bool $flagged = false): User_Model
    {
        static::__acting_as_site($site_id);

        if ($login_user === null) {
            $login_user = new Login_User_Model();
            $login_user->email = 'password_audit_' . uniqid() . '@example.com';
            $login_user->set_password(self::PASSWORD);
            $login_user->is_activated = true;
            $login_user->is_verified = true;
            $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
            if ($flagged) {
                $login_user->require_password_reset();
            }
            $login_user->save();
        }

        $user = new User_Model();
        $user->site_id = $site_id;
        $user->login_user_id = $login_user->id;
        $user->email = $login_user->email;
        $user->first_name = 'Audit';
        $user->last_name = 'Fixture';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->invite_accepted_at = now();
        $user->save();

        Session::logout();
        static::__reset_session();

        return $user;
    }

    private static function __login_user(User_Model $user): Login_User_Model
    {
        return Login_User_Model::where('id', $user->login_user_id)->first();
    }

    private static function __last_run_id(): int
    {
        return (int) Task_Run_Model::max('id');
    }

    /**
     * Run every record_password_event queued after $after (as a worker would) and answer their
     * params, so a test asserts how many the action produced.
     */
    private static function __run_queued(int $after): array
    {
        $runs = Task_Run_Model::where('id', '>', $after)
            ->where('method', 'record_password_event')
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
     * The password rows on $site_id about $user, newest last.
     */
    private static function __rows(User_Model $user, int $site_id = self::SITE_ID): array
    {
        Session::set_temporary_site_id($site_id);
        try {
            return Action_Log_Model::where('subject_type', 'User_Model')
                ->where('subject_id', $user->id)
                ->whereIn('type_id', [Action_Log_Model::TYPE_PASSWORD_CHANGED, Action_Log_Model::TYPE_PASSWORD_RESET_REQUESTED])
                ->orderBy('id')
                ->get()
                ->all();
        } finally {
            Session::clear_temporary_site_id();
        }
    }

    private static function __notices_to(string $email): int
    {
        return Email_Queue_Model::without_site_scope(
            fn () => Email_Queue_Model::where('to_address', $email)->where('email_class', 'Password_Changed_Email')->count()
        );
    }

    private static function __assert_one_row(User_Model $user, int $type, ?string $cause, ?int $actor_login_user_id, ?string $address): Action_Log_Model
    {
        $rows = static::__rows($user);
        static::__assert_count(1, $rows, 'exactly one password row');
        $row = $rows[0];

        static::__assert_equals($type, (int) $row->type_id);
        static::__assert_equals($cause, $row->get_metadata()['cause'] ?? null, 'the cause');
        static::__assert_equals($actor_login_user_id, $row->actor_id === null ? null : (int) $row->actor_id, 'the actor');

        static::__assert_equals($address, $row->audit_ip_address(), "the row's address is the event's audit session's");
        if ($address !== null) {
            static::__assert_contains(' from ' . $address, $row->render(), 'the line prints the address');
        }

        return $row;
    }

    /**
     * pa-01: Change Password in Settings - cause self, the user as actor, a notice.
     */
    public static function test_a_change_in_settings()
    {
        $user = static::__member();
        static::__as_signed_in_browser((int) $user->id);
        $after = static::__last_run_id();

        $result = Frontend_Settings_Password_Security_Controller::change_password(new Request(), [
            'current_password' => self::PASSWORD,
            'new_password' => self::NEW_PASSWORD,
            'confirm_password' => self::NEW_PASSWORD,
        ]);
        static::__assert_true($result['changed'] ?? false, 'changed');

        static::__assert_count(1, static::__run_queued($after), 'one audit run');
        $row = static::__assert_one_row($user, Action_Log_Model::TYPE_PASSWORD_CHANGED, Password_Change::CAUSE_SELF, (int) $user->login_user_id, self::ADDRESS);
        static::__assert_contains('changed their password', $row->render());
        static::__assert_equals(1, static::__notices_to($user->email), 'the owner is told');
    }

    /**
     * pa-02: a reset REQUEST and its COMPLETION are two rows; the link sends the notice.
     */
    public static function test_a_reset_request_and_its_completion()
    {
        $user = static::__member();
        $after = static::__last_run_id();

        Rsx_Turnstile::$force_verify_result_for_tests = true;
        Password_Reset_Controller::request(Request::create('/password/reset', 'POST', [
            'email' => $user->email,
            Rsx_Turnstile::FIELD => Rsx_Turnstile::is_enabled() ? 'test-token' : Rsx_Turnstile::INACTIVE,
        ]), []);

        $params = static::__run_queued($after);
        static::__assert_count(1, $params, 'the request is one run');
        static::__assert_equals('reset_requested', $params[0]['event']);
        static::__assert_one_row($user, Action_Log_Model::TYPE_PASSWORD_RESET_REQUESTED, null, (int) $user->login_user_id, null);

        $after = static::__last_run_id();
        $link = Login_User_Password_Reset_Model::create_for(static::__login_user($user));
        Password_Reset_Controller::reset(Request::create('/password/reset/x', 'POST', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]), ['token' => $link->get_secret()]);

        static::__assert_count(1, static::__run_queued($after), 'the completion is one run');
        $rows = static::__rows($user);
        static::__assert_count(2, $rows, 'the request row and the completion row');
        static::__assert_equals(Password_Change::CAUSE_RESET_LINK, $rows[1]->get_metadata()['cause']);
        static::__assert_equals((int) $user->login_user_id, (int) $rows[1]->actor_id, 'the owner acted');
        static::__assert_equals(1, static::__notices_to($user->email), 'the owner is told');
    }

    /**
     * pa-03: an administrator's reset - cause admin, the administrator as actor, on BOTH users'
     * histories, open links ended, no email.
     */
    public static function test_an_administrator_reset_is_on_both_histories()
    {
        Rsx_Password_Confirmation::$force_confirmed_for_tests = true;

        $admin = static::__member(User_Model::ROLE_SITE_ADMIN);
        $target = static::__member();
        $link = Login_User_Password_Reset_Model::create_for(static::__login_user($target));

        static::__as_signed_in_browser((int) $admin->id);
        $after = static::__last_run_id();

        Frontend_Settings_User_Management_Controller::set_user_password(new Request(), [
            'id' => $target->id,
            'new_password' => 'read-this-out-loud',
        ]);

        static::__assert_true(Login_User_Password_Reset_Model::find($link->id)->is_used(), 'the open reset link is ended');

        static::__assert_count(1, static::__run_queued($after), 'one audit run');
        $row = static::__assert_one_row($target, Action_Log_Model::TYPE_PASSWORD_CHANGED, Password_Change::CAUSE_ADMIN, (int) $admin->login_user_id, self::ADDRESS);
        static::__assert_contains('set a temporary password for', $row->render());
        static::__assert_equals(0, static::__notices_to($target->email), 'an administrator reset mails nothing');

        $target_history = Frontend_Settings_User_Management_Controller::get_user_activity(new Request(), ['id' => $target->id]);
        $admin_history = Frontend_Settings_User_Management_Controller::get_user_activity(new Request(), ['id' => $admin->id]);

        static::__assert_true(in_array((int) $row->id, array_column($target_history['activity'], 'id')), "on the target's history (done TO them)");
        static::__assert_true(in_array((int) $row->id, array_column($admin_history['activity'], 'id')), "on the administrator's history (done BY them)");

        $entry = $target_history['activity'][array_search((int) $row->id, array_column($target_history['activity'], 'id'))];
        static::__assert_equals(self::ADDRESS, $entry['ip_address'], 'the entry carries its address');
        static::__assert_array_has_key('ip_address__location', $entry);
    }

    /**
     * pa-04: the forced change at sign-in - cause forced_change, the owner as actor, a notice.
     */
    public static function test_a_forced_change_at_sign_in()
    {
        $user = static::__member(flagged: true);

        Rsx_Turnstile::$force_verify_result_for_tests = true;
        Login_Controller::index(Request::create('/login', 'POST', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            Rsx_Turnstile::FIELD => Rsx_Turnstile::is_enabled() ? 'test-token' : Rsx_Turnstile::INACTIVE,
        ]), []);

        $after = static::__last_run_id();
        Login_Controller::new_password(Request::create('/login/new-password', 'POST', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]), []);

        static::__assert_count(1, static::__run_queued($after), 'one audit run');
        static::__assert_one_row($user, Action_Log_Model::TYPE_PASSWORD_CHANGED, Password_Change::CAUSE_FORCED_CHANGE, (int) $user->login_user_id, null);
        static::__assert_equals(1, static::__notices_to($user->email), 'the owner is told');
    }

    /**
     * pa-05: the command line - cause cli, nobody acting, no address, no email; and a save with
     * no stated cause records 'unknown' and tells the owner.
     */
    public static function test_the_command_line_and_an_unstated_cause()
    {
        $user = static::__member();
        $after = static::__last_run_id();

        static::__acting_as_site(self::SITE_ID);   // the in-process command lists the memberships
        Artisan::call('rsx:users:password:set', ['--user' => (string) $user->login_user_id, '--password' => self::NEW_PASSWORD, '--json' => true]);

        static::__assert_count(1, static::__run_queued($after), 'one audit run');
        $rows = static::__rows($user);
        static::__assert_count(1, $rows);
        static::__assert_equals(Password_Change::CAUSE_CLI, $rows[0]->get_metadata()['cause']);
        static::__assert_null($rows[0]->actor_id, 'nobody acted on a command line');
        static::__assert_equals(0, static::__notices_to($user->email), 'a password the operator set mails nothing');

        $after = static::__last_run_id();
        $login_user = static::__login_user($user);
        $login_user->password = Login_User_Model::hash_password('assigned-without-a-cause');
        $login_user->save();

        static::__assert_count(1, static::__run_queued($after));
        $rows = static::__rows($user);
        static::__assert_equals(Password_Change::CAUSE_UNKNOWN, $rows[1]->get_metadata()['cause']);
        static::__assert_contains('was changed', $rows[1]->render());
        static::__assert_equals(1, static::__notices_to($user->email), 'an unstated change is told');
    }

    /**
     * pa-06: an identity on two sites gets a row on each, its subject that site's membership.
     */
    public static function test_every_site_of_the_identity_records_it()
    {
        $site = new Site_Model();
        $site->name = 'Password Audit Second Site';
        $site->slug = 'password-audit-' . uniqid();
        $site->save();

        $first = static::__member();
        $second = static::__member(User_Model::ROLE_USER, static::__login_user($first), (int) $site->id);
        $after = static::__last_run_id();

        $login_user = static::__login_user($first);
        $login_user->set_password(self::NEW_PASSWORD, Password_Change::CAUSE_SELF);
        $login_user->save();

        static::__assert_count(1, static::__run_queued($after), 'one run for the identity');
        static::__assert_count(1, static::__rows($first), 'a row on the first site');
        static::__assert_count(1, static::__rows($second, (int) $site->id), 'a row on the second site');
    }

    /**
     * pa-07: every place this application changes an existing password states its cause; the
     * three that set one only while CREATING an identity are named here.
     */
    public static function test_every_template_path_states_a_cause()
    {
        $creation_only = [
            'rsx/app/login/accept_invite/accept_invite_controller.php',
            'rsx/portal/auth/Portal_Register_Controller.php',
            'rsx/app/dev/acl/dev_acl_controller.php',
        ];

        $root = dirname(base_path());
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/rsx', \FilesystemIterator::SKIP_DOTS));
        $unstated = [];
        $stated = 0;

        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if (!str_ends_with($path, '.php') || str_starts_with($path, 'rsx/tests/') || in_array($path, $creation_only, true)) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (!str_contains($line, '->set_password(')) {
                    continue;
                }
                if (str_contains($line, 'Password_Change::CAUSE_')) {
                    $stated++;
                } else {
                    $unstated[] = $path . ':' . ($number + 1);
                }
            }
        }

        static::__assert_equals([], $unstated, 'every password change states its cause');
        static::__assert_greater_than(4, $stated, 'the change paths were found');
    }

    /**
     * pa-08: the notice carries the line a security notice needs and no way to turn it off.
     */
    public static function test_the_notice_has_the_line_and_no_preferences_footer()
    {
        $preview = Rsx_Mail::preview(Password_Changed_Email::sample());
        $html = html_entity_decode((string) $preview['html'], ENT_QUOTES);

        static::__assert_equals(Password_Changed_Email::SECURITY, Password_Changed_Email::CATEGORY);
        static::__assert_contains("If this wasn't you, contact an administrator.", $html);
        static::__assert_contains('203.0.113.7', $html, 'from which address');
        static::__assert_false(stripos($html, 'unsubscribe') !== false, 'no unsubscribe link');
        static::__assert_false(stripos($html, 'notification') !== false, 'no notification-preferences wording');
    }
}
