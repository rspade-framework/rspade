<?php
/**
 * CODING CONVENTION: snake_case for variable_names and function_names.
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Portal\Settings\Portal_Settings_Controller;
use Rsx\Portal_Permission;

/**
 * Portal_Impersonation_Test - the APPLICATION's half of "View as Client":
 *   - read-only enforcement: a write endpoint refuses while impersonating, a read
 *     endpoint still works (important - all Ajax endpoints are POST, so read-only
 *     cannot be a blanket POST block; it is per-write-endpoint via
 *     Portal_Permission::is_read_only()).
 *   - the guard fires ONLY under impersonation.
 *   - the staff-side contact -> portal-user resolution that powers the button.
 *
 * The portal identity/impersonation context is seeded with Portal_Session CLI
 * setters; the full staff HTTP begin->claim flow is smoke-tested via rsx:debug.
 * Runs in the default per-test transaction (rolled back afterward).
 */
class Portal_Impersonation_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;
    private const IMPERSONATOR_ID = 99;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Portal_Session::reset();
    }

    private static function __make_portal_user(?int $contact_id = null): Portal_User_Model
    {
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
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
    }

    /**
     * Put the (CLI) portal session into an impersonation of $portal_user_id.
     */
    private static function __impersonate(int $portal_user_id): void
    {
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

        $res = Portal_Settings_Controller::change_password(new Request(), [
            'current_password' => 'secret-password',
            'new_password' => 'brand-new-password',
            'confirm_password' => 'brand-new-password',
        ]);

        static::__assert_instance_of(Error_Response::class, $res, 'write rejected while impersonating');
        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $res->get_error_code(), 'rejected as unauthorized (read-only)');
    }

    public static function test_read_endpoint_still_works_while_impersonating()
    {
        $user = static::__make_portal_user();
        static::__impersonate($user->id);

        // Reads must NOT be blocked - the portal has to load for the staff member.
        $res = Portal_Settings_Controller::get_profile(new Request(), []);

        static::__assert_true(is_array($res), 'read endpoint returns data while impersonating');
        static::__assert_equals($user->email, $res['email'] ?? null, 'reads resolve the impersonated user');
    }

    public static function test_write_guard_does_not_fire_when_not_impersonating()
    {
        static::__reset_portal_cli();

        $user = static::__make_portal_user();
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($user->id);
        // No impersonator flag set.

        static::__assert_false(Portal_Permission::is_read_only(), 'not read-only without impersonation');

        // A wrong current password must reach validation (got past the read-only
        // guard) - i.e. it is NOT the read-only unauthorized response.
        $res = Portal_Settings_Controller::change_password(new Request(), [
            'current_password' => 'wrong-password',
            'new_password' => 'brand-new-password',
            'confirm_password' => 'brand-new-password',
        ]);

        static::__assert_instance_of(Error_Response::class, $res, 'wrong password is rejected');
        static::__assert_true($res->get_error_code() !== Ajax::ERROR_UNAUTHORIZED, 'rejection is validation, not read-only');
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
}
