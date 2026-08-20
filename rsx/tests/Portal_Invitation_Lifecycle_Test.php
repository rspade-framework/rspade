<?php
/**
 * CODING CONVENTION: snake_case for variable_names and function_names.
 */

namespace Rsx\Tests;

use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Portal_Invitation_Model;
use Rsx\Services\Portal_Invitation_Service;

/**
 * Portal_Invitation_Lifecycle_Test - the explicit invitation status lifecycle
 * (PENDING / USED / EXPIRED / REVOKED) and the hourly expiry task. Status is the
 * authoritative validity signal; used_at/expires_at remain for timing.
 *
 * Runs in the default per-test transaction (rolled back afterward).
 */
class Portal_Invitation_Lifecycle_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __make_invite(array $metadata = []): Portal_Invitation_Model
    {
        return Portal_Invitation_Model::create_invitation(self::SITE_ID, 'inv_' . uniqid() . '@example.com', $metadata);
    }

    public static function test_create_is_pending_and_valid()
    {
        $invite = static::__make_invite();

        static::__assert_equals(Portal_Invitation_Model::STATUS_PENDING, (int) $invite->status_id, 'new invite is pending');
        static::__assert_true($invite->is_valid(), 'pending + live invite is valid');
        static::__assert_false($invite->is_used(), 'not used');
        static::__assert_false($invite->is_revoked(), 'not revoked');
    }

    public static function test_mark_used_transitions_to_used()
    {
        $invite = static::__make_invite();
        $invite->mark_used();

        static::__assert_equals(Portal_Invitation_Model::STATUS_USED, (int) $invite->status_id);
        static::__assert_true($invite->is_used());
        static::__assert_false($invite->is_valid(), 'a used invite is no longer valid');
        static::__assert_not_null($invite->used_at, 'used_at stamped');
    }

    public static function test_mark_expired_only_from_pending()
    {
        $pending = static::__make_invite();
        $pending->mark_expired();
        static::__assert_equals(Portal_Invitation_Model::STATUS_EXPIRED, (int) $pending->status_id, 'pending -> expired');

        $used = static::__make_invite();
        $used->mark_used();
        $used->mark_expired();
        static::__assert_equals(Portal_Invitation_Model::STATUS_USED, (int) $used->status_id, 'mark_expired is a no-op on a used invite');
    }

    public static function test_revoke_only_from_pending()
    {
        $pending = static::__make_invite();
        $pending->revoke();
        static::__assert_equals(Portal_Invitation_Model::STATUS_REVOKED, (int) $pending->status_id, 'pending -> revoked');
        static::__assert_true($pending->is_revoked());

        $used = static::__make_invite();
        $used->mark_used();
        $used->revoke();
        static::__assert_equals(Portal_Invitation_Model::STATUS_USED, (int) $used->status_id, 'revoke is a no-op on a used invite');
    }

    public static function test_pending_past_window_reads_expired()
    {
        $invite = static::__make_invite();
        $invite->expires_at = now()->subDay();
        $invite->save();

        static::__assert_true($invite->is_expired(), 'a still-pending invite past its window reads as expired');
        static::__assert_false($invite->is_valid(), 'and is not valid');
    }

    public static function test_find_pending_excludes_consumed()
    {
        $invite = Portal_Invitation_Model::create_invitation(self::SITE_ID, 'fp_' . uniqid() . '@example.com', [
            'contact_id' => 4242,
            'client_id' => 8484,
        ]);

        static::__assert_not_null(
            Portal_Invitation_Model::find_pending_for_contact_client(4242, 8484),
            'a live invite is found'
        );

        $invite->mark_used();
        static::__assert_null(
            Portal_Invitation_Model::find_pending_for_contact_client(4242, 8484),
            'a consumed invite is no longer pending'
        );
    }

    public static function test_expire_stale_task_marks_only_stale_pending()
    {
        $stale = static::__make_invite();
        $stale->expires_at = now()->subDay();
        $stale->save();

        $live = static::__make_invite(); // +14 days, pending

        $used = static::__make_invite();
        $used->expires_at = now()->subDay();
        $used->mark_used();

        $task = new Task_Instance('Portal_Invitation_Service', 'expire_stale', [], 'default', true);
        $result = Portal_Invitation_Service::expire_stale($task, []);

        static::__assert_true(($result['expired'] ?? 0) >= 1, 'at least the stale pending invite was expired');
        static::__assert_equals(Portal_Invitation_Model::STATUS_EXPIRED, (int) $stale->fresh()->status_id, 'stale pending -> expired');
        static::__assert_equals(Portal_Invitation_Model::STATUS_PENDING, (int) $live->fresh()->status_id, 'live pending untouched');
        static::__assert_equals(Portal_Invitation_Model::STATUS_USED, (int) $used->fresh()->status_id, 'used untouched');
    }
}
