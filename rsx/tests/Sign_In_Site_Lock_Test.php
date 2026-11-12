<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\SignIn\Turnstile\Rsx_Turnstile;

/**
 * The invitation sign-in endpoints work under the per-request site locks.
 *
 * Both accept-invite endpoints are called through Ajax::internal(), the real dispatch path:
 * the endpoint runs inside a request handler window, where a site-scoped write needs the
 * site's write lock and the bare site setter is refused. Each declares #[Manual_Lock] (the
 * session has no site to lock yet), writes the invited membership in a Site_Lock::write()
 * section on the invitation's site, and then enters that site through RsxAuth::enter_site().
 * A regression to an automatic lock, or to a bare Session::set_site_id(), fails here as an
 * Ajax error instead of a redirect.
 */
class Sign_In_Site_Lock_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one (Rsx_Test_Abstract::__as_browser()). */
    protected static $acts_as_browser = true;

    private const SITE_ID = 1;

    private const PASSWORD = 'a-long-enough-password';

    public static function teardown(): void
    {
        Session::logout();
        static::__reset_session();
    }

    /** An open invitation to $email on SITE_ID: a membership row carrying an invite code. */
    private static function __invite(string $email): User_Model
    {
        $invite = new User_Model();
        $invite->site_id = self::SITE_ID;
        $invite->login_user_id = null;
        $invite->email = $email;
        $invite->first_name = 'Invited';
        $invite->last_name = 'Person';
        $invite->role_id = User_Model::ROLE_USER;
        $invite->is_enabled = true;
        $invite->invite_code = 'code_' . bin2hex(random_bytes(8));
        $invite->invite_expires_at = now()->addDays(7);
        User_Model::without_site_scope(fn () => $invite->save());

        return $invite;
    }

    public static function test_create_account_from_an_invitation_signs_in_through_dispatch()
    {
        $email = 'lock_invitee_' . uniqid() . '@example.com';
        $invite = static::__invite($email);

        $result = Ajax::internal('Accept_Invite_Controller', 'create_account_submit', [
            'email' => $email,
            'first_name' => 'Locked',
            'last_name' => 'Invitee',
            'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
            'invite_code' => $invite->invite_code,
            Rsx_Turnstile::FIELD => Rsx_Turnstile::INACTIVE,
        ]);

        static::__assert_equals(Rsx::Route('Accept_Invite_Controller::success'), $result['redirect'] ?? null, 'the endpoint answers the success page: ' . json_encode($result));

        $login_user = Login_User_Model::where('email', $email)->first();
        static::__assert_not_null($login_user, 'the account was created');

        $membership = User_Model::without_site_scope(fn () => User_Model::find($invite->id));
        static::__assert_equals((int) $login_user->id, (int) $membership->login_user_id, 'the membership is linked inside the write section');
        static::__assert_not_null($membership->invite_accepted_at, 'and the invitation accepted');
        static::__assert_equals('Locked', $membership->first_name);

        static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'signed in');
        static::__assert_equals(self::SITE_ID, (int) Session::get_site_id(), 'and the session entered the invited site');
    }

    public static function test_accepting_an_invitation_while_signed_in_enters_its_site_through_dispatch()
    {
        $email = 'lock_existing_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_verified = 0;
        $login_user->is_activated = 1;
        $login_user->save();

        $invite = static::__invite($email);

        // The identity needs an enabled membership to be signed in at all: the invitation's
        // row is one once it names the identity, so link it and sign in with no site bound.
        $invite->login_user_id = $login_user->id;
        User_Model::without_site_scope(fn () => $invite->save());
        static::__assert_true(RsxAuth::login($login_user), 'the identity signs in');

        $result = Ajax::internal('Accept_Invite_Controller', 'accept', ['code' => $invite->invite_code]);

        static::__assert_equals(
            Rsx::Route('Site_Selection_Controller::select', self::SITE_ID),
            $result['redirect_url'] ?? null,
            'the endpoint answers the site route: ' . json_encode($result)
        );

        $membership = User_Model::without_site_scope(fn () => User_Model::find($invite->id));
        static::__assert_not_null($membership->invite_accepted_at, 'the invitation is accepted');
        static::__assert_equals(1, (int) Login_User_Model::find($login_user->id)->is_verified, 'the address is verified');
        static::__assert_equals(self::SITE_ID, (int) Session::get_site_id(), 'the session entered the invited site');
    }
}
