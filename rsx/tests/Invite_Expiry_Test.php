<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use Rsx\App\Login\Invite_Helper;

/**
 * Invite_Helper::validate_invitation()'s expiry: an invitation is refused once its
 * invite_expires_at has passed - to the minute, the same day included - and is valid before
 * it, or with no expiry at all.
 *
 * The same-day case is the one that matters: invite_expires_at is an ISO string, and a
 * string compared with now() is compared as TEXT, which reads an expiry earlier today as
 * still valid ('T' sorts after the space in Carbon's own text form).
 */
class Invite_Expiry_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    /**
     * A pending invitation on the site, expiring at $expires_at (null = never).
     */
    private static function __invitation(?string $expires_at): string
    {
        $code = bin2hex(random_bytes(16));

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'invite_expiry_' . uniqid() . '@example.com';
        $user->first_name = 'Invited';
        $user->last_name = 'Person';
        $user->role_id = static::most_privileged_role_id();
        $user->invite_code = $code;
        $user->invite_expires_at = $expires_at;
        $user->save();

        return $code;
    }

    public static function test_an_invitation_that_expired_a_minute_ago_is_refused()
    {
        $now = Rsx_Time::now();
        if ((int) $now->format('H') === 0 && (int) $now->format('i') === 0) {
            static::__skip('a minute ago is yesterday at 00:00 UTC; the same-day case cannot be built this minute');

            return;
        }

        $code = static::__invitation(Rsx_Time::to_iso($now->copy()->subMinute()));

        $result = Invite_Helper::validate_invitation($code, false);

        static::__assert_false($result['valid'], 'expired a minute ago, earlier today');
        static::__assert_equals('expired', $result['error_type']);
    }

    public static function test_an_invitation_expiring_in_a_minute_is_valid()
    {
        $code = static::__invitation(Rsx_Time::to_iso(Rsx_Time::now()->addMinute()));

        $result = Invite_Helper::validate_invitation($code, false);

        static::__assert_true($result['valid'], 'not yet expired');
    }

    public static function test_an_invitation_with_no_expiry_is_valid()
    {
        $code = static::__invitation(null);

        static::__assert_true(Invite_Helper::validate_invitation($code, false)['valid']);
    }
}
