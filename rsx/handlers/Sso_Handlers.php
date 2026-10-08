<?php

namespace Rsx\Handlers;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Sso\Rsx_Sso;
use App\RSpade\Core\Sso\Sso_Failed_Exception;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Login\Login_Controller;

/**
 * Sso_Handlers
 *
 * What THIS application does with a federated sign-in. The framework owns the whole
 * ceremony - state, the token exchange, the throttle, the failure record - and asks this
 * file four questions it has no business answering on its own:
 *
 *   sso.identity.unlinked     a provider proved somebody owns a Google account, and no
 *                             local account is connected to it. Now what?
 *   sso.login.authorize       may THIS account sign in at all?
 *   sso.two_factor.verify_url where is this application's second-factor challenge page?
 *   sso.login.destination     where does a signed-in identity land?
 *
 * The first is the whole policy decision and the other three are wiring. Every one of them
 * fails CLOSED or fails LOUD when this file is missing: an unanswered sso.identity.unlinked
 * sends the user back to the login page with "No account is connected to this sign-in", and
 * an unanswered sso.two_factor.verify_url is a shouldnt_happen(). Deleting a handler here
 * switches a behaviour off; it never opens one up.
 *
 * See: php artisan rsx:man sso
 */
class Sso_Handlers
{
    /**
     * THE POLICY: a federated sign-in signs in an account that ALREADY EXISTS, and does
     * nothing else. Accounts are created in advance by an administrator (an invitation,
     * accepted with a password); a provider is only ever a way into one of them.
     *
     * The one way in: a VERIFIED provider email that matches a login_users row. The provider
     * asserted that this person controls that address, and controlling the address is how
     * this application already lets people prove who they are. The identity is connected and
     * signed in in one step. Anything else declines, and the framework's fail-closed refusal
     * ("No account is connected to this sign-in.") is the answer - an open invitation to the
     * address included: an invitee accepts the invitation link they were emailed.
     *
     * AN UNVERIFIED EMAIL IS NEVER MATCHED, AND THIS IS THE ACCOUNT-TAKEOVER RULE OF THE
     * WHOLE SUBSYSTEM. A provider that lets a user type any address into a profile and hands
     * it over unverified is handing over a CLAIM, not a fact - and matching a claim against
     * login_users means anybody who can name your email address at such a provider can sign
     * in as you, with no password and no notification. Facebook can withhold email entirely
     * and X may return none at all, so the null case is ordinary here rather than exotic.
     * The email_verified flag is the provider's own assertion, and it is the only thing that
     * makes the match safe. Never widen this condition to "we have an email".
     *
     * THE OTHER MODES an application can implement here, each a rewrite of this one method
     * (recipes in full: php artisan rsx:man sso):
     *
     *   AUTO-PROVISION - any provider identity gets an account. Create the Login_User_Model
     *     (and this application's User_Model site profile) from the identity, then
     *     Rsx_Sso::consume_pending_and_login() it. Open signup, expressed through SSO.
     *   FINISH REGISTRATION - redirect to a page of your own that shows the pending identity
     *     (Rsx_Sso::pending() is safe to render), collects whatever the product needs beyond
     *     an email address, and calls Rsx_Sso::link_pending() on submit.
     *
     * @param array $data {provider_key, provider_user_key, email, email_verified, name, avatar_url}
     * @return string|null The URL to send the browser to, or null to decline.
     */
    #[OnEvent('sso.identity.unlinked', priority: 10)]
    public static function match_verified_email_of_existing_account($data)
    {
        // X can return no address at all, and Facebook can withhold one. There is nothing to
        // match on, so there is nothing to decide.
        $email = isset($data['email']) ? trim((string) $data['email']) : '';

        if ($email === '' || empty($data['email_verified'])) {
            return null;
        }

        $login_user = Login_User_Model::where('email', $email)->first();

        if ($login_user === null) {
            // No account at that address. The framework discards the pending identity and
            // says so.
            return null;
        }

        try {
            return Rsx_Sso::consume_pending_and_login($login_user);
        } catch (Sso_Failed_Exception $e) {
            // The pending window closed while the user was deciding, the provider account
            // was connected elsewhere in the meantime, or sso.login.authorize below denied
            // the sign-in. All three carry a user-safe sentence by contract, and all three
            // end at the login page - returning null here would replace that sentence with
            // the framework's generic refusal.
            Flash_Alert::error($e->getMessage());

            return Rsx::Route('Login_Controller::index');
        }
    }

    /**
     * May this account sign in through a provider?
     *
     * IT MIRRORS THE PASSWORD DOOR, WHICH IS THE ONLY CORRECT DEFAULT. A federated sign-in
     * that enforced MORE than the password login would be a confusing product; one that
     * enforced LESS would be a way around the checks - and a check that exists on one door
     * only is worse than no check, because the weaker door is the one nobody remembers.
     *
     * So what does this application's password login actually enforce? Read
     * Login_Controller::index() and RsxAuth::verify_credentials() together and the answer is: a LIVE
     * login_users row (SoftDeletes' global scope makes a trashed identity a not-found) and a
     * correct password, plus at least one ENABLED site membership - users.is_enabled is the
     * FRAMEWORK's switch, refused by RsxAuth::verify_credentials() and by RsxAuth::login(), so it already
     * applies to a federated sign-in with nothing written here. Nothing else. This application
     * adds no suspended flag, no activation gate and no email-verification gate on the way in.
     *
     * This handler therefore permits, and its value is the SEAM: the one place to add an
     * account-state rule, and the reminder that adding it here alone leaves the password
     * door open. When this application grows a suspended/pending-approval state, it is
     * enforced HERE and in Login_Controller::index(), in the same change.
     *
     * @param array $data {login_user: Login_User_Model, identity: Sso_Identity_Model}
     * @return bool|string true to permit; a string denies and is SHOWN TO THE USER.
     */
    #[OnEvent('sso.login.authorize', priority: 10)]
    public static function authorize_login($data)
    {
        return true;
    }

    /**
     * Where this application asks for a second factor.
     *
     * The framework resolves this BEFORE it begins the challenge, because beginning one logs
     * the session out - so a null answer here is a misconfiguration it refuses to act on
     * rather than a browser left logged out with nowhere to go.
     *
     * @param array $data {login_user: Login_User_Model}
     * @return string
     */
    #[OnEvent('sso.two_factor.verify_url', priority: 10)]
    public static function two_factor_verify_url($data)
    {
        return Rsx::Route('Login_Controller::verify');
    }

    /**
     * Where a federated sign-in lands - the same place a password sign-in lands.
     *
     * ONE function computes it, Login_Controller::post_login_destination(), and this handler
     * is its third caller. A destination computed twice drifts, and the drift would show up
     * as "signing in with Google skips the site picker", which is a bug nobody reports as one.
     *
     * There is no invite code on this path: an invitation is accepted through
     * Accept_Invite_Controller, never through a provider callback.
     *
     * @param array $data {login_user: Login_User_Model}
     * @return string
     */
    #[OnEvent('sso.login.destination', priority: 10)]
    public static function login_destination($data)
    {
        return Login_Controller::post_login_destination((int) $data['login_user']->id, null);
    }

    /**
     * Where a completed CONNECT lands.
     *
     * The only place this app offers "Connect" is the Password & Security screen, so the
     * return trip goes back there - the user should see the row they just created. The
     * framework default is '/', which strands them on the dashboard with only a flash to
     * say it worked.
     *
     * @param array $data {login_user: Login_User_Model, identity: array}
     * @return string
     */
    #[OnEvent('sso.link.destination', priority: 10)]
    public static function link_destination($data)
    {
        return Rsx::Route('Settings_Password_Security_Action');
    }
}
