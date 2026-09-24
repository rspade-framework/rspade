<?php

namespace Rsx\Handlers;

use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Sso\Rsx_Portal_Sso;
use App\RSpade\Core\Sso\Sso_Failed_Exception;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\Portal\Auth\Portal_Login_Controller;

/**
 * Portal_Sso_Handlers
 *
 * What THIS application does with a federated sign-in on the CLIENT PORTAL. The portal realm
 * has its own hooks - portal.sso.* - and they are a different set from the staff sso.* hooks
 * in Sso_Handlers on purpose: the staff policy matches addresses against login_users, and it
 * must never run for a client. Nothing here is reached unless the portal realm is switched
 * on (rsx.sso.portal_enabled, off by default).
 *
 *   portal.sso.identity.unlinked      a provider proved somebody owns an account, and no
 *                                     portal user on this site is connected to it. Now what?
 *   portal.sso.login.authorize        may THIS portal user sign in at all?
 *   portal.sso.two_factor.verify_url  where is the portal's second-factor challenge page?
 *   portal.sso.login.destination      where does a signed-in portal user land?
 *   portal.sso.link.destination       where does a completed Connect land?
 *
 * Every one fails CLOSED or LOUD when missing, exactly as the staff hooks do.
 *
 * See: php artisan rsx:man sso
 */
class Portal_Sso_Handlers
{
    /**
     * THE POLICY: a provider identity is connected to a portal user this site ALREADY HAS,
     * by a VERIFIED address, and to nothing else. A portal account exists because a contact
     * was invited to it (rsx/portal/CLAUDE.md), so "Continue with Google" is never a way to
     * create one here - an address with no portal account declines, and the framework's
     * fail-closed refusal is the answer.
     *
     * AN UNVERIFIED EMAIL IS NEVER MATCHED - the account-takeover rule Sso_Handlers states in
     * full. Microsoft, Facebook and X assert no email_verified at all, so on this portal they
     * connect only through the Connect button on the Settings screen, by a portal user who
     * is already signed in. An application that wants them at sign-in instead resolves an
     * open invitation here and links inside the account creation (the invite branch in
     * rsx:man sso).
     *
     * The lookup is on the DECLARED site: Portal_User_Model::find_by_email() takes the site,
     * and a portal account is a (site, email) pair.
     *
     * @param array $data {provider_key, provider_user_key, email, email_verified, name, avatar_url}
     * @return string|null The URL to send the browser to, or null to decline.
     */
    #[OnEvent('portal.sso.identity.unlinked', priority: 10)]
    public static function match_verified_email($data)
    {
        $email = isset($data['email']) ? trim((string) $data['email']) : '';

        if ($email === '' || empty($data['email_verified'])) {
            return null;
        }

        $portal_user = Portal_User_Model::find_by_email(Portal_Session::get_site_id(), $email);

        if ($portal_user === null) {
            return null;
        }

        try {
            return Rsx_Portal_Sso::consume_pending_and_login($portal_user);
        } catch (Sso_Failed_Exception $e) {
            // The window closed, the account was connected elsewhere meanwhile, or the portal
            // refused the sign-in (can_login(), or the authorize gate below). Each carries a
            // user-safe sentence; returning null would replace it with the generic refusal.
            Flash_Alert::error($e->getMessage());

            return Rsx_Portal::Route('Portal_Login_Controller::index');
        }
    }

    /**
     * May this portal user sign in through a provider?
     *
     * IT MIRRORS THE PASSWORD DOOR. The portal's password login admits a portal user
     * Portal_User_Model::can_login() accepts (active and verified), and the framework already
     * applies that same rule to every portal sign-in, federated included. This application
     * adds nothing beyond it, so the handler permits - and is the ONE place to add a rule,
     * which must then be added to Portal_Login_Controller::index() in the same change.
     *
     * @param array $data {portal_user: Portal_User_Model, identity: Portal_Sso_Identity_Model}
     * @return bool|string true to permit; a string denies and is SHOWN TO THE USER.
     */
    #[OnEvent('portal.sso.login.authorize', priority: 10)]
    public static function authorize_login($data)
    {
        return true;
    }

    /**
     * Where the portal asks for a second factor.
     *
     * @param array $data {portal_user: Portal_User_Model}
     * @return string
     */
    #[OnEvent('portal.sso.two_factor.verify_url', priority: 10)]
    public static function two_factor_verify_url($data)
    {
        return Rsx_Portal::Route('Portal_Login_Controller::verify');
    }

    /**
     * Where a federated sign-in lands - the same place every other portal sign-in lands,
     * computed by the one function Portal_Login_Controller::post_auth_destination().
     *
     * @param array $data {portal_user: Portal_User_Model}
     * @return string
     */
    #[OnEvent('portal.sso.login.destination', priority: 10)]
    public static function login_destination($data)
    {
        return Portal_Login_Controller::post_auth_destination($data['portal_user'], 0);
    }

    /**
     * Where a completed Connect lands: back on the Settings screen that offered it, so the
     * portal user sees the connection they just made.
     *
     * @param array $data {portal_user: Portal_User_Model}
     * @return string
     */
    #[OnEvent('portal.sso.link.destination', priority: 10)]
    public static function link_destination($data)
    {
        return Rsx_Portal::Route('Portal_Settings_Action');
    }
}
