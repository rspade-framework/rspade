<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Every staff endpoint under rsx/app carries this application's data checks.
 *
 * The permission model (rsx/permission.php) is only as real as the endpoints that name it,
 * so this class reads the manifest's surface index - every #[Ajax_Endpoint] and
 * #[Api_Endpoint] declared under rsx/app/ (the closed rsx/app/dev showcase excepted) with
 * the #[Auth] checks it carries - and fails on an endpoint that slipped through:
 *
 *   WRITES. An endpoint whose METHOD NAME is write-shaped - one of its underscore-separated
 *   words is in WRITE_WORDS - must name can_edit_data or an administrative check
 *   (ADMIN_CHECKS). The word list is deliberately broad: a false positive costs one entry in
 *   the exemption maps below, while a missed write is the escalation this test exists for.
 *
 *   READS. Every other endpoint must name SOME check beyond is_logged_in / public:
 *   can_view_data for records, can_view_user_activity for the activity log, an
 *   administrative check for a settings screen.
 *
 * The only endpoints excused are listed below BY NAME with the reason, and a listed name
 * that no longer exists fails too, so the list cannot rot into a blanket pass.
 */
class Endpoint_Data_Gates_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * A method name containing any of these words (split on '_') is a write.
     */
    private const WRITE_WORDS = [
        'accept', 'add', 'approve', 'archive', 'assign', 'attach', 'block', 'bulk', 'cancel',
        'change', 'create', 'delete', 'deny', 'disable', 'edit', 'enable', 'grant', 'import',
        'invite', 'mark', 'move', 'post', 'publish', 'reactivate', 'reject', 'remove', 'rename',
        'reply', 'resend', 'restore', 'review', 'revoke', 'save', 'send', 'set', 'share',
        'submit', 'suspend', 'toggle', 'unshare', 'update', 'upload',
    ];

    /**
     * A write passes when it names can_edit_data or one of these.
     */
    private const ADMIN_CHECKS = [
        'can_manage_users', 'can_manage_site_settings', 'can_manage_billing',
        'is_developer', 'is_framework_developer', 'closed',
    ];

    /**
     * Endpoints that act on the CALLER's own records, never on the site's data, so the
     * data checks do not apply.
     */
    private const SELF_SERVICE = [
        'Frontend_Notifications_Controller::get_count' => 'the caller\'s own notification count',
        'Frontend_Notifications_Controller::get_dropdown' => 'the caller\'s own notifications',
        'Frontend_Notifications_Controller::mark_all_read' => 'the caller\'s own notifications',
        'Frontend_Notifications_Controller::mark_read' => 'the caller\'s own notifications',
        'Frontend_Settings_Password_Security_Controller::change_password' => 'the caller\'s own password',
        'Frontend_Settings_Password_Security_Controller::revoke_session' => 'the caller\'s own sessions',
        'Frontend_Settings_Profile_Display_Controller::get_profile' => 'the caller\'s own profile',
        'Frontend_Settings_Profile_Edit_Controller::get_profile_for_edit' => 'the caller\'s own profile',
        'Frontend_Settings_Profile_Edit_Controller::save_profile' => 'the caller\'s own profile',
        'Frontend_Settings_User_Settings_Controller::update' => 'the caller\'s own preferences',
        'Frontend_Settings_Api_Keys_Controller::create_key' => 'the caller\'s own API keys (gated can_use_api)',
        'Frontend_Settings_Api_Keys_Controller::datagrid_fetch' => 'the caller\'s own API keys (gated can_use_api)',
        'Frontend_Settings_Api_Keys_Controller::get_key_scopes' => 'the caller\'s own API keys (gated can_use_api)',
        'Frontend_Settings_Api_Keys_Controller::get_scope_presets' => 'the caller\'s own API keys (gated can_use_api)',
        'Frontend_Settings_Api_Keys_Controller::preview_scopes' => 'the caller\'s own API keys (gated can_use_api)',
        'Frontend_Settings_Api_Keys_Controller::revoke_key' => 'the caller\'s own API keys (gated can_use_api)',
    ];

    /**
     * The public sign-in and sign-up ladder: authorized by the credential or invitation
     * code it is handed, before any identity exists.
     */
    private const PUBLIC_LADDER = [
        'Accept_Invite_Controller::accept' => 'authorized by the invitation code',
        'Accept_Invite_Controller::create_account_submit' => 'authorized by the invitation code',
        'Login_Controller::passkey_login' => 'authorized by the passkey',
        'Login_Controller::verify_2fa' => 'authorized by the parked second-factor challenge',
        'Signup_Controller::submit' => 'public registration, governed by rsx.auth.signup_mode',
    ];

    /**
     * Every rsx/app Ajax and API surface: target => its #[Auth] checks.
     *
     * @return array<string, string[]>
     */
    private static function __app_endpoints(): array
    {
        $endpoints = [];

        foreach (Auth_Gates::get_surfaces() as $target => $surface) {
            $file = (string) ($surface['file'] ?? '');

            if (!str_starts_with($file, 'rsx/app/') || str_starts_with($file, 'rsx/app/dev/')) {
                continue;
            }

            if (!array_intersect($surface['kinds'] ?? [], ['ajax', 'api'])) {
                continue;
            }

            $endpoints[$target] = array_values($surface['auth'] ?? []);
        }

        ksort($endpoints);

        return $endpoints;
    }

    private static function __is_write(string $target): bool
    {
        $method = substr($target, strrpos($target, '::') + 2);

        return (bool) array_intersect(explode('_', strtolower($method)), self::WRITE_WORDS);
    }

    private static function __is_excused(string $target): bool
    {
        return isset(self::SELF_SERVICE[$target]) || isset(self::PUBLIC_LADDER[$target]);
    }

    public static function test_the_surface_index_lists_the_application_endpoints()
    {
        $endpoints = static::__app_endpoints();

        static::__assert_greater_than(50, count($endpoints), 'the manifest lists the rsx/app endpoints');
        static::__assert_array_has_key('Frontend_Clients_Controller::save', $endpoints, 'a known write is indexed');
    }

    public static function test_every_write_names_can_edit_data_or_an_administrative_check()
    {
        $missing = [];

        foreach (static::__app_endpoints() as $target => $checks) {
            if (!static::__is_write($target) || static::__is_excused($target)) {
                continue;
            }

            if (!in_array('can_edit_data', $checks, true) && !array_intersect($checks, self::ADMIN_CHECKS)) {
                $missing[] = $target . ' [' . implode(', ', $checks) . ']';
            }
        }

        static::__assert_empty(
            $missing,
            "Write-shaped endpoints without can_edit_data or an administrative check:\n  " . implode("\n  ", $missing)
        );
    }

    public static function test_every_other_endpoint_names_a_check_beyond_signed_in()
    {
        $missing = [];

        foreach (static::__app_endpoints() as $target => $checks) {
            if (static::__is_excused($target)) {
                continue;
            }

            if (!array_diff($checks, ['is_logged_in', 'public'])) {
                $missing[] = $target . ' [' . implode(', ', $checks) . ']';
            }
        }

        static::__assert_empty(
            $missing,
            "Endpoints gated on sign-in alone (add can_view_data, can_view_user_activity or an administrative check):\n  "
            . implode("\n  ", $missing)
        );
    }

    public static function test_every_excused_endpoint_still_exists()
    {
        $endpoints = static::__app_endpoints();
        $stale = [];

        foreach (array_keys(self::SELF_SERVICE + self::PUBLIC_LADDER) as $target) {
            if (!isset($endpoints[$target])) {
                $stale[] = $target;
            }
        }

        static::__assert_empty($stale, "Excused endpoints that no longer exist - remove them:\n  " . implode("\n  ", $stale));
    }
}
