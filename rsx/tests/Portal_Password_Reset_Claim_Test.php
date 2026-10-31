<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use Rsx\Models\Portal_Password_Reset_Model;
use Rsx\Portal\Auth\Portal_Password_Reset_Controller;

/**
 * A portal password-reset link is single-use even when two submissions race on it:
 * Portal_Password_Reset_Model::claim() uses the link up with one conditional UPDATE, and the
 * reset form writes the new password only after its own claim succeeded.
 *
 * Two real concurrent requests cannot be staged in one process, so the race is pinned at its
 * seam: a second claim of a link already claimed is refused, and a submission that arrives
 * after another request claimed the link writes nothing.
 */
class Portal_Password_Reset_Claim_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const OLD_PASSWORD = 'old-password-1';

    private const NEW_PASSWORD = 'new-password-2';

    private static function __portal_user(): Portal_User_Model
    {
        Portal_Session::set_site_id(self::SITE_ID);
        static::__acting_as_site(self::SITE_ID);

        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'reset_claim_' . uniqid() . '@example.com';
        $user->set_password(self::OLD_PASSWORD);
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    public static function test_a_link_is_claimed_once()
    {
        $reset = Portal_Password_Reset_Model::create_for_user(static::__portal_user());

        static::__assert_true($reset->claim(), 'the first claim uses the link up');
        static::__assert_false($reset->claim(), 'a second claim of the same link is refused');
        static::__assert_false(Portal_Password_Reset_Model::find_by_token($reset->token)->claim(), 'from a fresh read too');
        static::__assert_true($reset->is_used());
    }

    public static function test_an_expired_link_cannot_be_claimed()
    {
        $reset = Portal_Password_Reset_Model::create_for_user(static::__portal_user());
        $reset->expires_at = now()->subMinute();
        $reset->save();

        static::__assert_false($reset->claim());
        static::__assert_false($reset->fresh()->is_used(), 'and it is not marked used');
    }

    public static function test_a_submission_that_lost_the_claim_writes_no_password()
    {
        $user = static::__portal_user();
        $reset = Portal_Password_Reset_Model::create_for_user($user);

        // The other request won the link between this one's validity check and its write.
        $loaded = Portal_Password_Reset_Model::find_by_token($reset->token);
        static::__assert_true($loaded->is_valid(), 'this request saw a valid link');
        static::__assert_true($reset->claim(), 'the other request claims it');

        $request = Request::create('/password/reset/' . $reset->token, 'POST', [
            'password' => self::NEW_PASSWORD,
            'password_confirm' => self::NEW_PASSWORD,
            Rsx_Turnstile::FIELD => Rsx_Turnstile::INACTIVE,
        ]);
        Portal_Password_Reset_Controller::reset($request, ['token' => $reset->token]);

        $after = $user->fresh();
        static::__assert_true($after->check_password(self::OLD_PASSWORD), 'the password is unchanged');
        static::__assert_false($after->check_password(self::NEW_PASSWORD));
    }

    public static function test_a_valid_submission_claims_and_writes()
    {
        $user = static::__portal_user();
        $reset = Portal_Password_Reset_Model::create_for_user($user);

        $request = Request::create('/password/reset/' . $reset->token, 'POST', [
            'password' => self::NEW_PASSWORD,
            'password_confirm' => self::NEW_PASSWORD,
            Rsx_Turnstile::FIELD => Rsx_Turnstile::INACTIVE,
        ]);
        Portal_Password_Reset_Controller::reset($request, ['token' => $reset->token]);

        static::__assert_true($user->fresh()->check_password(self::NEW_PASSWORD), 'the new password is set');
        static::__assert_true($reset->fresh()->is_used(), 'and the link is used up');
    }
}
