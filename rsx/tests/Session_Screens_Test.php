<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Settings\PasswordSecurity\Frontend_Settings_Password_Security_Controller;
use Rsx\App\Frontend\Settings\UserManagement\Frontend_Settings_User_Management_Controller;
use Rsx\Lib\Sessions\Session_Listing;
use Rsx\Portal\Settings\Portal_Settings_Controller;

/**
 * The "where you're signed in" screens: an administrator's view of one user of this site
 * (user management, with a page per session), your own (Password & Security) and a portal
 * user's own (portal settings).
 *
 * What is pinned: every list is the framework's get_sessions_for_user() cut by
 * Session_Listing - active within the last three months, signed-out sessions included - and
 * an administrator sees only this site's; a session id handed to an endpoint must be one the
 * screen lists for that user, so another user's, another site's or a dormant one is not
 * found; Sign out ends a signed-in session of somebody else's browser, is refused for a
 * superior (equal-or-lower administration) and for the browser making the request, and
 * "everywhere" stays on this site.
 *
 * Sessions are written with the harness's session fixture: nobody can sign in on another
 * browser from a test. The test process is a virtual session, so is_current is never true
 * here; the browser making the request is a fixture made current with __as_browser().
 */
class Session_Screens_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const OTHER_SITE_ID = 4242;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Portal_Session::cli_set_portal_user_id(0);
        static::__reset_session();
    }

    /**
     * A signed-up member of the site at a role (login identity + membership).
     */
    private static function __member(int $role_id, string $label): User_Model
    {
        $email = 'session_screens_' . $label . '_' . uniqid() . '@example.com';

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
        $user->first_name = ucfirst($label);
        $user->last_name = 'Member';
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

    /**
     * A browser signed in as $user on $site_id; $staff overrides the sign-in's columns.
     * Returns the staff user session id.
     */
    private static function __signed_in(User_Model $user, int $site_id = self::SITE_ID, array $staff = []): int
    {
        return static::__insert_session_fixture([
            'staff' => array_merge(['login_user_id' => (int) $user->login_user_id, 'site_id' => $site_id], $staff),
        ])['staff_user_session_id'];
    }

    private static function __is_signed_in(int $login_user_id, int $user_session_id): bool
    {
        foreach (Session::get_sessions_for_user($login_user_id, null, true) as $session) {
            if ($session['id'] === $user_session_id) {
                return $session['is_signed_in'];
            }
        }

        static::__fail("user session {$user_session_id} is not listed for login identity {$login_user_id}");
    }

    private static function __assert_error(string $code, $response, string $message): void
    {
        static::__assert_true($response instanceof Error_Response, "{$message}: expected an error response");
        static::__assert_equals($code, $response->get_error_code(), $message);
    }

    // ---------------------------------------------------------------------
    // user management
    // ---------------------------------------------------------------------

    /**
     * The view page lists this site's sessions of the user, signed out too, cut at three
     * months; each row says whether Sign out is offered.
     */
    public static function test_the_user_view_lists_this_sites_recent_sessions()
    {
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN, 'admin');
        $member = static::__member(User_Model::ROLE_MANAGER, 'member');

        $recent = static::__signed_in($member, self::SITE_ID, ['last_active_at' => now()->subMonths(2)]);
        $signed_out = static::__signed_in($member, self::SITE_ID, [
            'signed_out_at' => now()->subDay(),
            'sign_out_reason_id' => Session::SIGN_OUT_SIGNED_OUT,
            'last_active_at' => now()->subDay(),
        ]);
        static::__signed_in($member, self::SITE_ID, ['last_active_at' => now()->subMonths(Session_Listing::LISTED_WITHIN_MONTHS + 1)]);
        static::__signed_in($member, self::OTHER_SITE_ID);

        static::__act_as($admin);
        $rows = array_column(
            Frontend_Settings_User_Management_Controller::get_user(new Request(), ['id' => $member->id])['sessions'],
            null,
            'id'
        );

        static::__assert_equals([$signed_out, $recent], array_keys($rows), 'this site, active within three months, most recent first');
        static::__assert_true($rows[$recent]['can_sign_out'], 'a signed-in session offers Sign out');
        static::__assert_false($rows[$signed_out]['can_sign_out'], 'a signed-out one does not');
        static::__assert_equals('Signed out', $rows[$signed_out]['sign_out_reason'], 'and says why it ended');
        static::__assert_false(array_key_exists('session_id', $rows[$recent]), 'the browser id never leaves');
    }

    /**
     * A session page answers only for a session the user's view page lists: another user's,
     * another site's and a dormant one are not found, exactly like an id that does not exist.
     */
    public static function test_the_session_page_answers_only_a_listed_session()
    {
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN, 'admin');
        $member = static::__member(User_Model::ROLE_MANAGER, 'member');
        $other = static::__member(User_Model::ROLE_MANAGER, 'other');

        $mine = static::__signed_in($member);
        $theirs = static::__signed_in($other);
        $elsewhere = static::__signed_in($member, self::OTHER_SITE_ID);
        $dormant = static::__signed_in($member, self::SITE_ID, ['last_active_at' => now()->subMonths(Session_Listing::LISTED_WITHIN_MONTHS + 1)]);

        static::__act_as($admin);

        $page = Frontend_Settings_User_Management_Controller::get_user_session(new Request(), ['id' => $member->id, 'user_session_id' => $mine]);
        static::__assert_equals($mine, $page['session']['id'], 'a listed session');
        static::__assert_equals($member->id, $page['user']['id'], 'of the user');
        static::__assert_true($page['can_administer'], 'which the caller administers');

        $activity = Frontend_Settings_User_Management_Controller::get_user_session_activity(new Request(), ['id' => $member->id, 'user_session_id' => $mine]);
        static::__assert_equals(0, $activity['total'], 'a session that changed nothing has no activity');

        foreach (['another user\'s' => $theirs, 'another site\'s' => $elsewhere, 'a dormant' => $dormant, 'a missing' => 999999999] as $label => $id) {
            static::__assert_error(Ajax::ERROR_NOT_FOUND, Frontend_Settings_User_Management_Controller::get_user_session(new Request(), ['id' => $member->id, 'user_session_id' => $id]), "{$label} session");
            static::__assert_error(Ajax::ERROR_NOT_FOUND, Frontend_Settings_User_Management_Controller::get_user_session_activity(new Request(), ['id' => $member->id, 'user_session_id' => $id]), "{$label} session's activity");
        }
    }

    /**
     * Sign out ends one signed-in session of the user (recorded as ended by an
     * administrator); a second time it is not found; a superior's session is refused and
     * stays signed in; "everywhere" ends this site's sessions and leaves another site's.
     */
    public static function test_an_administrator_signs_a_users_sessions_out()
    {
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN, 'admin');
        $member = static::__member(User_Model::ROLE_MANAGER, 'member');
        $owner = static::__member(User_Model::ROLE_SITE_OWNER, 'owner');

        $one = static::__signed_in($member);
        $two = static::__signed_in($member);
        $three = static::__signed_in($member);
        $elsewhere = static::__signed_in($member, self::OTHER_SITE_ID);
        $superior = static::__signed_in($owner);

        static::__act_as($admin);

        $result = Frontend_Settings_User_Management_Controller::terminate_user_session(new Request(), ['id' => $member->id, 'user_session_id' => $one]);
        static::__assert_equals(1, $result['signed_out'], 'one session signed out');
        static::__assert_false(static::__is_signed_in((int) $member->login_user_id, $one), 'it is signed out');

        $listed = array_column(Session::get_sessions_for_user((int) $member->login_user_id, self::SITE_ID), null, 'id');
        static::__assert_equals('Ended by an administrator', $listed[$one]['sign_out_reason'], 'recorded as an administrator\'s');

        static::__assert_error(Ajax::ERROR_NOT_FOUND, Frontend_Settings_User_Management_Controller::terminate_user_session(new Request(), ['id' => $member->id, 'user_session_id' => $one]), 'a signed-out session');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, Frontend_Settings_User_Management_Controller::terminate_user_session(new Request(), ['id' => $member->id, 'user_session_id' => $superior]), 'another user\'s session through this user');

        static::__assert_error(Ajax::ERROR_UNAUTHORIZED, Frontend_Settings_User_Management_Controller::terminate_user_session(new Request(), ['id' => $owner->id, 'user_session_id' => $superior]), 'a superior');
        static::__assert_error(Ajax::ERROR_UNAUTHORIZED, Frontend_Settings_User_Management_Controller::terminate_all_user_sessions(new Request(), ['id' => $owner->id]), 'a superior, everywhere');
        static::__assert_true(static::__is_signed_in((int) $owner->login_user_id, $superior), 'whose session stays signed in');

        $result = Frontend_Settings_User_Management_Controller::terminate_all_user_sessions(new Request(), ['id' => $member->id]);
        static::__assert_equals(2, $result['signed_out'], 'the rest of this site\'s sessions');
        static::__assert_false(static::__is_signed_in((int) $member->login_user_id, $two) || static::__is_signed_in((int) $member->login_user_id, $three), 'signed out');
        static::__assert_true(static::__is_signed_in((int) $member->login_user_id, $elsewhere), 'another site\'s session is that site\'s business');
    }

    // ---------------------------------------------------------------------
    // Password & Security - your own sessions
    // ---------------------------------------------------------------------

    /**
     * Your own list: every site, never an impersonation of you, cut at three months.
     */
    public static function test_your_own_sessions_are_listed()
    {
        $me = static::__member(User_Model::ROLE_MANAGER, 'me');
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN, 'admin');

        $here = static::__signed_in($me);
        $elsewhere = static::__signed_in($me, self::OTHER_SITE_ID, ['last_active_at' => now()->subMinute()]);
        static::__signed_in($me, self::SITE_ID, ['impersonator_login_user_id' => (int) $admin->login_user_id]);
        static::__signed_in($me, self::SITE_ID, ['last_active_at' => now()->subMonths(Session_Listing::LISTED_WITHIN_MONTHS + 1)]);

        static::__act_as($me);
        $ids = array_column(Frontend_Settings_Password_Security_Controller::get_sessions(new Request())['sessions'], 'id');

        static::__assert_equals([$here, $elsewhere], $ids, 'every site; no impersonation of you; nothing dormant');
    }

    /**
     * Sign out ends one of your other sessions; the browser making the request, a signed-out
     * session and somebody else's are not found; "all other sessions" spares this browser.
     */
    public static function test_you_sign_your_other_sessions_out()
    {
        $me = static::__member(User_Model::ROLE_MANAGER, 'me');
        $someone = static::__member(User_Model::ROLE_MANAGER, 'someone');

        $other = static::__signed_in($me);
        $another = static::__signed_in($me);
        $theirs = static::__signed_in($someone);

        static::__act_as($me);
        $browser = static::__as_browser(['staff' => ['login_user_id' => (int) $me->login_user_id, 'site_id' => self::SITE_ID]]);
        $this_browser = (int) collect(Session::get_sessions_for_user((int) $me->login_user_id))->firstWhere('session_id', $browser)['id'];

        $result = Frontend_Settings_Password_Security_Controller::sign_out_session(new Request(), ['user_session_id' => $other]);
        static::__assert_equals(1, $result['signed_out'], 'another device signed out');
        static::__assert_false(static::__is_signed_in((int) $me->login_user_id, $other), 'it is signed out');

        static::__assert_error(Ajax::ERROR_NOT_FOUND, Frontend_Settings_Password_Security_Controller::sign_out_session(new Request(), ['user_session_id' => $other]), 'a signed-out session');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, Frontend_Settings_Password_Security_Controller::sign_out_session(new Request(), ['user_session_id' => $this_browser]), 'this browser');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, Frontend_Settings_Password_Security_Controller::sign_out_session(new Request(), ['user_session_id' => $theirs]), 'somebody else\'s session');
        static::__assert_true(static::__is_signed_in((int) $someone->login_user_id, $theirs), 'which stays signed in');

        $result = Frontend_Settings_Password_Security_Controller::sign_out_other_sessions(new Request());
        static::__assert_equals(1, $result['signed_out'], 'the remaining other device');
        static::__assert_false(static::__is_signed_in((int) $me->login_user_id, $another), 'signed out');
        static::__assert_true(static::__is_signed_in((int) $me->login_user_id, $this_browser), 'this browser stays signed in');
    }

    // ---------------------------------------------------------------------
    // portal settings
    // ---------------------------------------------------------------------

    /**
     * A portal user's own list, cut at three months, and Sign out on another device; another
     * portal user's session is not found and stays signed in.
     */
    public static function test_a_portal_user_lists_and_signs_out_their_sessions()
    {
        $portal_user = static::__portal_user();
        $stranger = static::__portal_user();

        $portal = fn (Portal_User_Model $user, array $columns = []) => static::__insert_session_fixture([
            'portal' => array_merge(['portal_user_id' => (int) $user->id, 'portal_site_id' => self::SITE_ID], $columns),
        ])['portal_user_session_id'];

        $device = $portal($portal_user);
        $portal($portal_user, ['last_active_at' => now()->subMonths(Session_Listing::LISTED_WITHIN_MONTHS + 1)]);
        $theirs = $portal($stranger);

        Portal_Session::cli_set_portal_user_id((int) $portal_user->id);

        $rows = Portal_Settings_Controller::get_sessions(new Request())['sessions'];
        static::__assert_equals([$device], array_column($rows, 'id'), 'the portal user\'s own, nothing dormant');
        static::__assert_true($rows[0]['can_sign_out'], 'another device offers Sign out');

        static::__assert_error(Ajax::ERROR_NOT_FOUND, Portal_Settings_Controller::terminate_session(new Request(), ['user_session_id' => $theirs]), 'another portal user\'s session');

        $result = Portal_Settings_Controller::terminate_session(new Request(), ['user_session_id' => $device]);
        static::__assert_true(is_array($result), 'the device is signed out');

        $after = array_column(Portal_Session::get_sessions_for_user((int) $portal_user->id), null, 'id');
        static::__assert_false($after[$device]['is_signed_in'], 'it is signed out');
        static::__assert_true(array_column(Portal_Session::get_sessions_for_user((int) $stranger->id), null, 'id')[$theirs]['is_signed_in'], 'the stranger\'s stays signed in');
    }

    private static function __portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'session_screens_portal_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }
}
