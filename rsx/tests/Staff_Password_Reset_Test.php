<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Messaging\Mail\Email_Queue_Model;
use App\RSpade\Lib\SignIn\Confirmation\Rsx_Password_Confirmation;
use App\RSpade\Lib\SignIn\Turnstile\Rsx_Turnstile;
use App\RSpade\Lib\SignIn\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Lib\SignIn\TwoFactor\Totp;
use Rsx\App\Frontend\Settings\UserManagement\Frontend_Settings_User_Management_Controller;
use Rsx\App\Login\Login_Controller;
use Rsx\App\Login\PasswordReset\Password_Reset_Controller;
use Rsx\Emails\Password_Reset_Email;
use Rsx\Models\Login_User_Password_Reset_Model;

/**
 * This application's staff password resets, end to end through its controllers:
 *
 *  - FORCED CHANGE AT SIGN-IN: a flagged identity's correct password parks it; the "Choose a
 *    new password" POST saves the new password and signs in through complete_sign_in() - or,
 *    for an identity holding a second factor, sends it to the challenge with nobody signed in;
 *    the current password is refused there.
 *  - FORGOT PASSWORD: a request for a live account queues one Password_Reset_Email (SECURITY)
 *    and answers the same "sent" page as an unknown address (which queues nothing); the link
 *    sets the password and sends the visitor to sign in, signing nobody in; a used link and a
 *    superseded link are refused.
 *  - ADMINISTRATOR RESET: set_user_password() sets a temporary password flagged in the same
 *    save (an already-flagged user stays flagged), and is refused on the
 *    caller's own account, on a superior, on an identity that belongs to another site too, and
 *    without a password confirmation.
 *
 * The controllers are called as static methods, as the dispatcher calls them.
 */
class Staff_Password_Reset_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one. */
    protected static $acts_as_browser = true;

    private const SITE_ID = 1;

    private const PASSWORD = 'the-temporary-password';

    private const NEW_PASSWORD = 'the-users-own-password';

    public static function teardown(): void
    {
        Rsx_Turnstile::$force_verify_result_for_tests = null;
        Rsx_Password_Confirmation::$force_confirmed_for_tests = null;
        Session::logout();
        static::__reset_session();
    }

    private static function __turnstile_token(): string
    {
        if (!Rsx_Turnstile::is_enabled()) {
            return Rsx_Turnstile::INACTIVE;
        }

        Rsx_Turnstile::$force_verify_result_for_tests = true;

        return 'test-token';
    }

    /**
     * A signed-up member of site 1 at $role_id, optionally flagged. Leaves the session anonymous.
     */
    private static function __member(int $role_id = User_Model::ROLE_USER, bool $flagged = false): User_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $email = 'staff_reset_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->set_password(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;

        if ($flagged) {
            $login_user->require_password_reset();
        }

        $login_user->save();

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Reset';
        $user->last_name = 'Fixture';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->invite_accepted_at = now();
        $user->save();

        static::__anonymous();

        return $user;
    }

    private static function __anonymous(): void
    {
        Session::logout();
        static::__reset_session();
    }

    private static function __login_user(User_Model $user): Login_User_Model
    {
        return Login_User_Model::where('id', $user->login_user_id)->first();
    }

    private static function __row(User_Model $user): object
    {
        return DB::table('login_users')->where('id', $user->login_user_id)->first();
    }

    private static function __path($response): string
    {
        return (string) parse_url($response->getTargetUrl(), PHP_URL_PATH);
    }

    private static function __sign_in_post(User_Model $user, string $password)
    {
        return Login_Controller::index(Request::create('/login', 'POST', [
            'email' => $user->email,
            'password' => $password,
            Rsx_Turnstile::FIELD => static::__turnstile_token(),
        ]), []);
    }

    private static function __new_password_post(string $password, ?string $confirmation = null)
    {
        return Login_Controller::new_password(Request::create('/login/new-password', 'POST', [
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]), []);
    }

    // -------------------------------------------------------------------------
    // Forced change at sign-in
    // -------------------------------------------------------------------------

    /**
     * spr-01: flagged -> "Choose a new password" -> signed in through complete_sign_in(),
     * the flag cleared and the new password the one that works.
     */
    public static function test_a_forced_change_completes_the_sign_in()
    {
        $user = static::__member(flagged: true);

        $response = static::__sign_in_post($user, self::PASSWORD);
        static::__assert_equals(Rsx::Route('Login_Controller::new_password'), static::__path($response));
        static::__assert_null(Session::get_login_user_id(), 'not signed in at the step');

        $refused = static::__new_password_post(self::PASSWORD);
        static::__assert_contains('different from your current one', (string) $refused->getData()['error'], 'the current password is refused');

        $mismatch = static::__new_password_post(self::NEW_PASSWORD, 'something-else-entirely');
        static::__assert_equals('Passwords do not match', $mismatch->getData()['error']);

        $done = static::__new_password_post(self::NEW_PASSWORD);

        static::__assert_equals(Rsx::Route('Dashboard_Index_Action'), static::__path($done), 'landed as any sign-in lands');
        static::__assert_equals((int) $user->login_user_id, (int) Session::get_login_user_id(), 'signed in');

        $row = static::__row($user);
        static::__assert_equals(0, (int) $row->password_reset_required, 'the flag is cleared');
        static::__assert_true(Hash::check(self::NEW_PASSWORD, $row->password), 'the new password works');
        static::__assert_false(Hash::check(self::PASSWORD, $row->password), "the administrator's no longer does");
    }

    /**
     * spr-02: a flagged identity holding a second factor chooses its password FIRST and then
     * faces the challenge - nobody is signed in until it is answered.
     */
    public static function test_a_forced_change_is_followed_by_the_second_factor()
    {
        $user = static::__member(flagged: true);

        Session::set_login_user_id((int) $user->login_user_id);
        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));
        static::__anonymous();

        static::__sign_in_post($user, self::PASSWORD);
        $response = static::__new_password_post(self::NEW_PASSWORD);

        static::__assert_equals(Rsx::Route('Login_Controller::verify'), static::__path($response), 'the challenge comes next');
        static::__assert_null(Session::get_login_user_id(), 'nobody is signed in before the second factor');
        static::__assert_not_null(Rsx_Two_Factor::challenge_pending(), 'the challenge is pending');
        static::__assert_equals(0, (int) static::__row($user)->password_reset_required, 'the password was changed');
    }

    /**
     * spr-03: an unflagged identity signs in exactly as before.
     */
    public static function test_an_unflagged_identity_signs_in_as_before()
    {
        $user = static::__member();

        $response = static::__sign_in_post($user, self::PASSWORD);

        static::__assert_equals(Rsx::Route('Dashboard_Index_Action'), static::__path($response));
        static::__assert_equals((int) $user->login_user_id, (int) Session::get_login_user_id());
    }

    // -------------------------------------------------------------------------
    // Forgot password
    // -------------------------------------------------------------------------

    private static function __request_post(string $email)
    {
        return Password_Reset_Controller::request(Request::create('/password/reset', 'POST', [
            'email' => $email,
            Rsx_Turnstile::FIELD => static::__turnstile_token(),
        ]), []);
    }

    private static function __queued_for(string $email): int
    {
        return Email_Queue_Model::without_site_scope(
            fn () => Email_Queue_Model::where('to_address', $email)->where('email_class', 'Password_Reset_Email')->count()
        );
    }

    /**
     * spr-04: a live account is mailed ONE link (SECURITY); an unknown address is mailed nothing
     * and answered the same.
     */
    public static function test_a_request_mails_a_live_account_and_reveals_nothing()
    {
        $user = static::__member();
        $unknown = 'nobody_' . uniqid() . '@example.com';

        $known_view = static::__request_post($user->email);
        $unknown_view = static::__request_post($unknown);

        static::__assert_true($known_view->getData()['sent'], 'the known address is told "sent"');
        static::__assert_true($unknown_view->getData()['sent'], 'and so is the unknown one');
        static::__assert_equals(1, static::__queued_for($user->email), 'one link queued for the account');
        static::__assert_equals(0, static::__queued_for($unknown), 'nothing queued for the unknown address');

        $row = Email_Queue_Model::without_site_scope(
            fn () => Email_Queue_Model::where('to_address', $user->email)->first()
        );
        static::__assert_contains('/password/reset/', (string) $row->template_data['reset_url'], 'the email carries the link');
        static::__assert_equals(Password_Reset_Email::CATEGORY, (int) $row->category_id, 'a SECURITY email');
    }

    /**
     * spr-05: the link sets the password and sends the visitor to sign in, signing nobody in;
     * it cannot be used twice; a second request ends the first link.
     */
    public static function test_a_link_sets_the_password_once_and_a_newer_link_ends_it()
    {
        $user = static::__member(flagged: true);
        $login_user = static::__login_user($user);

        $first = Login_User_Password_Reset_Model::create_for($login_user);
        $second = Login_User_Password_Reset_Model::create_for($login_user);

        $superseded = Password_Reset_Controller::reset(Request::create('/password/reset/x', 'GET'), ['token' => $first->get_secret()]);
        static::__assert_contains('already been used', (string) $superseded->getData()['error'], 'the first link died with the second request');

        $post = fn () => Password_Reset_Controller::reset(Request::create('/password/reset/x', 'POST', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]), ['token' => $second->get_secret()]);

        $response = $post();

        static::__assert_equals(Rsx::Route('Login_Controller::index'), static::__path($response), 'sent to sign in');
        static::__assert_null(Session::get_login_user_id(), 'signed in by nothing');

        $row = static::__row($user);
        static::__assert_true(Hash::check(self::NEW_PASSWORD, $row->password), 'the new password is set');
        static::__assert_equals(0, (int) $row->password_reset_required, 'a reset clears a forced-reset flag');

        static::__assert_contains('already been used', (string) $post()->getData()['error'], 'a used link is refused');
    }

    // -------------------------------------------------------------------------
    // Administrator reset
    // -------------------------------------------------------------------------

    private static function __admin_sets(User_Model $target, string $password = self::PASSWORD)
    {
        return Frontend_Settings_User_Management_Controller::set_user_password(new Request(), [
            'id' => $target->id,
            'new_password' => $password,
        ]);
    }

    private static function __assert_refused($result, string $message): void
    {
        static::__assert_instance_of(Error_Response::class, $result, $message);
    }

    /**
     * spr-06: a temporary password, flagged in the same save - also over a user the CLI had
     * already flagged. (Ending the open reset links happens after the commit, on the
     * password-change event: Password_Audit_Test, which commits.)
     */
    public static function test_an_administrator_sets_a_temporary_password()
    {
        Rsx_Password_Confirmation::$force_confirmed_for_tests = true;

        $admin = static::__member(User_Model::ROLE_SITE_ADMIN);
        $target = static::__member(flagged: true);

        static::__acting_as_user((int) $admin->id);

        $result = static::__admin_sets($target, 'read-this-out-loud');

        static::__assert_true($result['password_reset_required']);

        $row = static::__row($target);
        static::__assert_true(Hash::check('read-this-out-loud', $row->password), 'the temporary password is set');
        static::__assert_equals(1, (int) $row->password_reset_required, 'still flagged though it was flagged already');
    }

    /**
     * spr-07: refused on your own account, on a superior, on an identity that also belongs to
     * another site, and without a password confirmation - nothing changes.
     */
    public static function test_an_administrator_reset_is_refused_where_it_must_be()
    {
        $admin = static::__member(User_Model::ROLE_SITE_ADMIN);
        $owner = static::__member(User_Model::ROLE_SITE_OWNER);
        $target = static::__member();

        static::__acting_as_user((int) $admin->id);

        Rsx_Password_Confirmation::$force_confirmed_for_tests = false;
        static::__assert_throws(\Throwable::class, fn () => static::__admin_sets($target), Rsx_Password_Confirmation::EXPIRED_MESSAGE);

        Rsx_Password_Confirmation::$force_confirmed_for_tests = true;

        static::__assert_refused(static::__admin_sets($admin), 'your own account');
        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, static::__admin_sets($owner)->get_error_code(), 'a superior');

        $site = new Site_Model();
        $site->slug = 'staff-reset-' . uniqid();
        $site->name = 'Another Site';
        $site->is_enabled = true;
        $site->save();

        $elsewhere = new User_Model();
        $elsewhere->site_id = (int) $site->id;
        $elsewhere->login_user_id = $target->login_user_id;
        $elsewhere->email = $target->email;
        $elsewhere->first_name = 'Reset';
        $elsewhere->last_name = 'Elsewhere';
        $elsewhere->role_id = User_Model::ROLE_USER;
        $elsewhere->is_enabled = true;
        User_Model::without_site_scope(fn () => $elsewhere->save());

        static::__assert_refused(static::__admin_sets($target), 'a member of another site');

        foreach ([$admin, $owner, $target] as $user) {
            $row = static::__row($user);
            static::__assert_true(Hash::check(self::PASSWORD, $row->password), 'nothing changed');
            static::__assert_equals(0, (int) $row->password_reset_required, 'nothing flagged');
        }

        $view = Frontend_Settings_User_Management_Controller::get_user(new Request(), ['id' => $admin->id]);
        static::__assert_false($view['can_reset_password'], 'the button is absent on your own account');
    }
}
