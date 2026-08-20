<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;

/**
 * RSX Authentication service
 *
 * Provides authentication logic for login/logout.
 * Authenticates against login_users table (authentication identity).
 * All session management is handled by the Session class directly.
 */
class RsxAuth
{
    /**
     * Attempt to authenticate a user with an email + password pair.
     *
     * RECORDING. attempt() is the only place that can see WHICH of the three outcomes happened -
     * unknown address, wrong password, or success - so it is the place that records them
     * (Login_History::record_failure() with STATUS_FAILED_NOT_FOUND / STATUS_FAILED_PASSWORD, or
     * record_success()). A caller never has to repeat the lookup to classify what it just got
     * back: a false return means "recorded, and the classification is already written down".
     * Failures are ephemeral (counters + one log line) and successes are a `_login_history` row -
     * see Login_History.
     *
     *   $record = false is the OPT-OUT for callers whose logins are not real logins - a test
     *   harness, a fixture, a challenge pre-check - so they cannot pollute a real audit trail
     *   with attempts nobody made.
     *
     * ACCOUNT STATE IS DELIBERATELY NOT CHECKED HERE. login_users.status_id, is_activated and
     * is_verified are APPLICATION vocabulary: they are meant to be overridden and they mean
     * whatever the application says they mean, so the framework refuses to guess. The app enforces
     * them in its own login function and in Main::pre_dispatch() (owner ruling 2026-08-12,
     * and pre_dispatch is the hook that can HALT a request - init() is bootstrap). What the
     * framework checks is exactly two things: the address resolves to a live login identity, and
     * the password matches.
     *
     * A SOFT-DELETED identity is therefore refused for free: Login_User_Model uses SoftDeletes, so
     * the global scope excludes trashed rows from the lookup below and a deleted account is
     * classified NOT_FOUND - the same answer a stranger's address gets, which is also the right
     * answer to give a stranger.
     *
     * TWO-FACTOR (or any other second step) is built on top, not inside:
     *
     *     if (!RsxAuth::attempt($credentials, record: false, touch_last_login: false)) { ... }
     *     // ... run the challenge; on completion:
     *     RsxAuth::login($login_user);
     *     Login_History::record_success($login_user->id, $email);
     *
     * The pre-check neither records nor stamps, so a recorded SUCCESS always means FULL
     * authentication and last_login is stamped only when the user is actually all the way in.
     *
     * See rsx:man session.
     *
     * @param array $credentials Requires 'email' and 'password'
     * @param bool $record Whether to record the outcome to Login_History (default true)
     * @param bool $touch_last_login Whether a successful login bumps last_login (default true)
     * @return bool
     */
    public static function attempt(array $credentials, bool $record = true, bool $touch_last_login = true)
    {
        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        // Malformed input is not an attempt: nobody offered a credential pair to check, so
        // there is nothing to classify and nothing to record.
        if (!$email || !$password) {
            return false;
        }

        // Authenticate against login_users table (authentication identity). SoftDeletes' global
        // scope excludes trashed identities here - a deleted account is NOT_FOUND.
        $login_user = Login_User_Model::where('email', $email)->first();

        if (!$login_user) {
            if ($record) {
                Login_History::record_failure($email, Login_History::STATUS_FAILED_NOT_FOUND);
            }

            return false;
        }

        if (!Hash::check($password, $login_user->password)) {
            if ($record) {
                Login_History::record_failure(
                    $email,
                    Login_History::STATUS_FAILED_PASSWORD,
                    null,
                    $login_user->id
                );
            }

            return false;
        }

        if ($record) {
            Login_History::record_success($login_user->id, $email);
        }

        return self::login($login_user, $touch_last_login);
    }

    /**
     * Log in a user and create session
     *
     * $touch_last_login is threaded straight through to Session::set_login_user_id(): true (the
     * default) stamps login_users.last_login, false leaves it alone. Pass false whenever the login
     * is not a real login - the dev-auth harness does (Dispatcher::__handle_dev_auth), for the
     * same reason an impersonation identity swap does.
     *
     * Records nothing. A caller that logs a user in directly - a second-factor completion, an
     * invitation acceptance - records its own SUCCESS through Login_History::record_success().
     *
     * @param Login_User_Model $login_user
     * @param bool $touch_last_login Whether to bump the login user's last_login timestamp
     * @return bool
     */
    public static function login(Login_User_Model $login_user, bool $touch_last_login = true)
    {
        // Use Session to set the login user (will create session if needed)
        Session::set_login_user_id($login_user->id, $touch_last_login);

        return true;
    }

    /**
     * Log out the current user
     *
     * @return void
     */
    public static function logout()
    {
        // Use Session to logout
        Session::logout();
    }
}
