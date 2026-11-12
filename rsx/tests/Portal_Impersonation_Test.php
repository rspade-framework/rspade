<?php
/**
 * CODING CONVENTION: snake_case for variable_names and function_names.
 */

namespace Rsx\Tests;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Portal\Portal_Session;
use App\RSpade\Lib\Portal\Portal_User_Model;
use App\RSpade\Lib\Portal\Rsx_Portal;
use Rsx\App\Frontend\Contacts\Frontend_Contacts_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Portal_Permission;

/**
 * Portal_Impersonation_Test - the APPLICATION's half of "View as Client":
 *   - read-only enforcement through the framework seam: an unmarked (write) endpoint
 *     is refused while impersonating, one marked #[Portal_Impersonation_Readable]
 *     still works (all Ajax endpoints are POST, so read-only cannot be a POST block).
 *   - the refusal fires ONLY under impersonation.
 *   - the staff-side contact -> portal-user resolution that powers the button.
 *   - the View as Client route (Frontend_Contacts_Controller::view_as_client, the GET
 *     a user-clicked new-tab link opens): refused unless the navigation started on this
 *     site, gated by this app's can_impersonate rule, redirecting to the portal's landing
 *     URL on the same host and to the first leg of the framework's linked-session
 *     handshake when PORTAL_URL puts the portal on its own host.
 *
 * The portal identity/impersonation context is seeded with Portal_Session CLI
 * setters. The handshake legs themselves are framework tests (Portal_Session_Link_Test
 * and session/http/session_link_handshake.sh).
 * Runs in the default per-test transaction (rolled back afterward).
 */
class Portal_Impersonation_Test extends Rsx_Test_Abstract
{
    /** View as Client moves a browser's portal sign-in, so every test acts as a browser. */
    protected static $acts_as_browser = true;

    private const SITE_ID = 1;
    private const IMPERSONATOR_ID = 99;

    private static $saved_portal_url;

    public static function setup(): void
    {
        self::$saved_portal_url = config('rsx.portal.url');
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        static::__reset_portal_cli();
        config(['rsx.portal.url' => self::$saved_portal_url]);
        static::__reset_session();
    }

    private static function __make_portal_user(?int $contact_id = null): Portal_User_Model
    {
        // Records are made in the staff realm; the realm is a process static that a
        // previous test in this class may have left on portal.
        Rsx_Portal::set_portal_request(false);

        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'impuser_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        if ($contact_id !== null) {
            $user->contact_id = $contact_id;
        }
        $user->save();

        return $user;
    }

    private static function __make_contact(string $email): Contact_Model
    {
        $client = new Client_Model();
        $client->name = 'Imp Client ' . uniqid();
        $client->save();

        $contact = new Contact_Model();
        $contact->site_id = self::SITE_ID;
        $contact->client_id = $client->id;
        $contact->first_name = 'Imp';
        $contact->last_name = 'Contact';
        $contact->email = $email;
        $contact->save();

        return $contact;
    }

    /**
     * Reset CLI portal/impersonation state. setup()/teardown() run once per class
     * (the DB rolls back per test, but these static flags do not), so tests that
     * assert a clean slate reset here first.
     */
    private static function __reset_portal_cli(): void
    {
        Rsx_Portal::set_portal_request(false);
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
    }

    /**
     * Put the (CLI) portal session into an impersonation of $portal_user_id.
     */
    private static function __impersonate(int $portal_user_id): void
    {
        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($portal_user_id);
        Portal_Session::cli_set_impersonator_user_id(self::IMPERSONATOR_ID);
    }

    // =====================================================================
    // read-only enforcement
    // =====================================================================

    public static function test_write_endpoint_is_blocked_while_impersonating()
    {
        $user = static::__make_portal_user();
        static::__impersonate($user->id);

        // Unmarked (a write): the framework refuses it before it runs.
        static::__assert_throws(AjaxUnauthorizedException::class, function () {
            Ajax::internal('Portal_Settings_Controller', 'change_password', [
                'current_password' => 'secret-password',
                'new_password' => 'brand-new-password',
                'confirm_password' => 'brand-new-password',
            ]);
        }, 'read-only session');

        static::__assert_true(
            Portal_User_Model::find($user->id)->check_password('secret-password'),
            'the password did not change'
        );
    }

    public static function test_read_endpoint_still_works_while_impersonating()
    {
        $user = static::__make_portal_user();
        static::__impersonate($user->id);

        // Marked #[Portal_Impersonation_Readable]: the portal has to load for the staff member.
        $res = Ajax::internal('Portal_Settings_Controller', 'get_profile');

        static::__assert_true(is_array($res), 'read endpoint returns data while impersonating');
        static::__assert_equals($user->email, $res['email'] ?? null, 'reads resolve the impersonated user');
    }

    public static function test_write_guard_does_not_fire_when_not_impersonating()
    {
        static::__reset_portal_cli();

        $user = static::__make_portal_user();
        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($user->id);
        // No impersonator flag set.

        static::__assert_false(Portal_Permission::is_read_only(), 'not read-only without impersonation');

        // A wrong current password must reach validation (got past the read-only
        // refusal) - i.e. it is NOT the read-only unauthorized answer.
        $e = static::__assert_throws(\Throwable::class, function () {
            Ajax::internal('Portal_Settings_Controller', 'change_password', [
                'current_password' => 'wrong-password',
                'new_password' => 'brand-new-password',
                'confirm_password' => 'brand-new-password',
            ]);
        });
        static::__assert_false($e instanceof AjaxUnauthorizedException, 'rejection is validation, not read-only');
    }

    public static function test_is_read_only_reflects_impersonation_flag()
    {
        static::__reset_portal_cli();

        static::__assert_false(Portal_Permission::is_read_only(), 'default is read-write');

        Portal_Session::cli_set_impersonator_user_id(self::IMPERSONATOR_ID);
        static::__assert_true(Portal_Permission::is_read_only(), 'impersonation -> read-only');

        Portal_Session::cli_set_impersonator_user_id(null);
        static::__assert_false(Portal_Permission::is_read_only(), 'cleared -> read-write');
    }

    // =====================================================================
    // staff-side contact -> portal-user resolution (powers the button + begin)
    // =====================================================================

    public static function test_resolve_portal_user_by_contact_link()
    {
        $contact = static::__make_contact('linked_' . uniqid() . '@example.com');
        $portal_user = static::__make_portal_user($contact->id);

        $resolved = $contact->resolve_portal_user();
        static::__assert_not_null($resolved, 'contact resolves its portal account');
        static::__assert_equals($portal_user->id, $resolved->id, 'resolves the linked portal user');
    }

    public static function test_resolve_portal_user_null_when_no_account()
    {
        $contact = static::__make_contact('noaccount_' . uniqid() . '@example.com');

        static::__assert_null($contact->resolve_portal_user(), 'no portal account -> null');
    }

    // =====================================================================
    // the begin endpoint
    // =====================================================================

    /**
     * A staff user with $role_id on the test site, signed in.
     */
    private static function __sign_in_staff(int $role_id): User_Model
    {
        Rsx_Portal::set_portal_request(false);
        static::__acting_as_site(self::SITE_ID);

        $email = 'impstaff_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make('secret-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Imp';
        $user->last_name = 'Staff';
        $user->role_id = $role_id;
        $user->is_enabled = 1;
        $user->save();

        static::__acting_as_user($user->id);

        return $user;
    }

    /**
     * The View as Client route as the contact page's link reaches it: a GET whose
     * Sec-Fetch-Site says where the navigation started.
     */
    private static function __view_as_client(int $contact_id, string $fetch_site = 'same-origin')
    {
        $request = Request::create(
            '/contacts/view/' . $contact_id . '/view-as-client',
            'GET',
            [],
            [],
            [],
            ['HTTP_SEC_FETCH_SITE' => $fetch_site]
        );

        return Frontend_Contacts_Controller::view_as_client($request, ['id' => $contact_id]);
    }

    public static function test_view_as_client_on_the_same_host_opens_the_portal_as_the_contact()
    {
        config(['rsx.portal.url' => '']);
        $staff = static::__sign_in_staff(User_Model::ROLE_MANAGER);
        $contact = static::__make_contact('begin_' . uniqid() . '@example.com');
        $portal_user = static::__make_portal_user($contact->id);
        Rsx_Portal::set_portal_request(false);

        $response = static::__view_as_client($contact->id);

        static::__assert_instance_of(RedirectResponse::class, $response, 'the tab is redirected');
        static::__assert_equals('/_portal/', parse_url($response->getTargetUrl(), PHP_URL_PATH), 'the portal itself, on this host');

        static::__assert_equals($portal_user->id, (int) Portal_Session::get_portal_user_id(), 'viewing as the contact\'s portal user');
        static::__assert_equals($staff->id, (int) Portal_Session::get_impersonator_user_id(), 'impersonated by the caller');
    }

    public static function test_view_as_client_with_the_portal_on_its_own_host_opens_the_handshake()
    {
        config(['rsx.portal.url' => 'https://portal.example.test']);
        static::__sign_in_staff(User_Model::ROLE_MANAGER);
        $contact = static::__make_contact('begin_far_' . uniqid() . '@example.com');
        static::__make_portal_user($contact->id);
        Rsx_Portal::set_portal_request(false);

        $response = static::__view_as_client($contact->id);

        static::__assert_instance_of(RedirectResponse::class, $response, 'the tab is redirected');
        static::__assert_true(
            str_starts_with($response->getTargetUrl(), 'https://portal.example.test/_session_link/open?'),
            'the first handshake leg, on the portal host; got ' . $response->getTargetUrl()
        );
    }

    public static function test_view_as_client_is_refused_below_the_manager_floor()
    {
        config(['rsx.portal.url' => '']);
        static::__sign_in_staff(User_Model::ROLE_USER);
        $contact = static::__make_contact('begin_low_' . uniqid() . '@example.com');
        static::__make_portal_user($contact->id);
        Rsx_Portal::set_portal_request(false);

        static::__assert_throws(AjaxUnauthorizedException::class, function () use ($contact) {
            static::__view_as_client($contact->id);
        });

        static::__assert_false(Portal_Session::is_impersonating(), 'nothing was started');
    }

    /**
     * A GET that starts an impersonation answers only a navigation begun on this site:
     * another site linking a signed-in manager to the route starts nothing.
     */
    public static function test_view_as_client_is_refused_for_a_cross_site_navigation()
    {
        config(['rsx.portal.url' => '']);
        static::__sign_in_staff(User_Model::ROLE_MANAGER);
        $contact = static::__make_contact('begin_xsite_' . uniqid() . '@example.com');
        static::__make_portal_user($contact->id);
        Rsx_Portal::set_portal_request(false);

        $response = static::__view_as_client($contact->id, 'cross-site');

        static::__assert_instance_of(Error_Response::class, $response, 'refused');
        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $response->get_error_code(), 'as unauthorized');
        static::__assert_false(Portal_Session::is_impersonating(), 'nothing was started');
    }
}
