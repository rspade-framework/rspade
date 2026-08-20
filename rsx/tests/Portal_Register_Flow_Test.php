<?php
/**
 * CODING CONVENTION: snake_case for variable_names and function_names.
 */

namespace Rsx\Tests;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use Rsx\Models\Client_Model;
use Rsx\Models\Portal_Invitation_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Portal\Auth\Portal_Register_Controller;

/**
 * Portal_Register_Flow_Test - the invite -> register decision tree:
 *   - a live PENDING invite creates the account + client membership and lands on
 *     the invited client's workspace;
 *   - an EXPIRED invite (acknowledged) creates the account ONLY (no membership) and
 *     lands on the dashboard;
 *   - a returning contact whose account already exists is routed to login;
 *   - a REVOKED invite shows the cancelled page (not a dead "invalid" error);
 *   - an expired invite without acknowledgement shows the expired page.
 *
 * Portal identity is CLI-seeded; reset at the top of each test since the register
 * flow logs the new user in (a leftover CLI login would short-circuit to dashboard).
 */
class Portal_Register_Flow_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;
    private const PASSWORD = 'newpassword123';

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Portal_Session::reset();
    }

    /**
     * Called at the top of every test: the runner resets the portal facade's statics
     * after EACH test (setup() runs once per class), so the site declaration and the
     * logged-out state both have to be re-established per test.
     */
    private static function __reset_portal_context(): void
    {
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id(0);
    }

    private static function __make_client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Reg Client ' . uniqid();
        $client->save();

        return $client;
    }

    private static function __make_invite(string $email, array $metadata = []): Portal_Invitation_Model
    {
        return Portal_Invitation_Model::create_invitation(self::SITE_ID, $email, $metadata);
    }

    private static function __register(array $params, bool $post = false)
    {
        $request = $post
            ? Request::create('/register', 'POST', [
                'password' => self::PASSWORD,
                'password_confirm' => self::PASSWORD,
                Rsx_Turnstile::FIELD => Rsx_Turnstile::INACTIVE,
            ])
            : Request::create('/register', 'GET');

        return Portal_Register_Controller::index($request, $params);
    }

    // =====================================================================

    public static function test_pending_invite_creates_account_membership_and_lands_on_client()
    {
        static::__reset_portal_context();

        $client = static::__make_client();
        $email = 'pending_' . uniqid() . '@example.com';
        $invite = static::__make_invite($email, [
            'client_id' => $client->id,
            'role_id' => Portal_Membership_Model::ROLE_VIEWER,
        ]);

        $response = static::__register(['code' => $invite->invitation_code], true);

        $user = Portal_User_Model::find_by_email(self::SITE_ID, $email);
        static::__assert_not_null($user, 'account created');
        static::__assert_true(
            Portal_Membership_Model::has_membership($user->id, $client->id),
            'invited client membership granted'
        );
        static::__assert_equals(Portal_Invitation_Model::STATUS_USED, (int) $invite->fresh()->status_id, 'invite consumed');

        static::__assert_instance_of(RedirectResponse::class, $response, 'redirects after register');
        static::__assert_true(
            str_contains($response->getTargetUrl(), '/workspace/' . $client->id),
            'lands on the invited client workspace'
        );
    }

    public static function test_expired_invite_creates_account_only_and_lands_on_dashboard()
    {
        static::__reset_portal_context();

        $client = static::__make_client();
        $email = 'expired_' . uniqid() . '@example.com';
        $invite = static::__make_invite($email, [
            'client_id' => $client->id,
            'role_id' => Portal_Membership_Model::ROLE_VIEWER,
        ]);
        $invite->mark_expired();

        $response = static::__register(['code' => $invite->invitation_code, 'allow_expired' => 1], true);

        $user = Portal_User_Model::find_by_email(self::SITE_ID, $email);
        static::__assert_not_null($user, 'account still created from an expired invite');
        static::__assert_false(
            Portal_Membership_Model::has_membership($user->id, $client->id),
            'expired invite grants NO client membership'
        );
        static::__assert_equals(Portal_Invitation_Model::STATUS_USED, (int) $invite->fresh()->status_id, 'invite consumed');

        static::__assert_instance_of(RedirectResponse::class, $response);
        static::__assert_false(
            str_contains($response->getTargetUrl(), '/workspace/'),
            'does NOT land on a client workspace (clientless dashboard)'
        );
    }

    public static function test_existing_account_is_routed_to_login()
    {
        static::__reset_portal_context();

        $email = 'existing_' . uniqid() . '@example.com';

        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = $email;
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        $client = static::__make_client();
        $invite = static::__make_invite($email, ['client_id' => $client->id]);

        $response = static::__register(['code' => $invite->invitation_code], false);

        static::__assert_equals(1, Portal_User_Model::where('email', $email)->count(), 'no second account created');
        static::__assert_equals(Portal_Invitation_Model::STATUS_USED, (int) $invite->fresh()->status_id, 'live invite consumed');

        static::__assert_instance_of(RedirectResponse::class, $response, 'routed to login');
        $target = $response->getTargetUrl();
        static::__assert_true(str_contains($target, 'login'), 'to the login screen');
        static::__assert_true(str_contains($target, 'account_exists'), 'with the account-exists message');
    }

    public static function test_revoked_invite_shows_cancelled_page()
    {
        static::__reset_portal_context();

        $email = 'revoked_' . uniqid() . '@example.com';
        $invite = static::__make_invite($email, []);
        $invite->revoke();

        $response = static::__register(['code' => $invite->invitation_code], false);

        static::__assert_false($response instanceof RedirectResponse, 'cancelled page is rendered, not a redirect');
        static::__assert_null(Portal_User_Model::find_by_email(self::SITE_ID, $email), 'no account created');
    }

    public static function test_expired_invite_without_ack_shows_expired_page()
    {
        static::__reset_portal_context();

        $email = 'expiredpage_' . uniqid() . '@example.com';
        $invite = static::__make_invite($email, []);
        $invite->mark_expired();

        $response = static::__register(['code' => $invite->invitation_code], false);

        static::__assert_false($response instanceof RedirectResponse, 'expired page is rendered, not a redirect');
        static::__assert_null(Portal_User_Model::find_by_email(self::SITE_ID, $email), 'no account created yet');
    }
}
