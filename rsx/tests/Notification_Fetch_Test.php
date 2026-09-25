<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Client_Model;
use Rsx\Models\Notification_Model;

/**
 * Notification_Model::fetch() - the ORM read of one notification.
 *
 *   - it serves the RECIPIENT only: a notification whose user_id (a login_users id) is not
 *     the caller's is answered exactly as a missing row;
 *   - an invalid notification (its entity is gone) is reported absent and NOT deleted - a
 *     read has no side effects; deleting is get_for_dropdown()'s self-policing.
 *
 * Runs in the default per-test transaction (rolled back afterward).
 */
class Notification_Fetch_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    public static function test_the_recipient_fetches_their_own()
    {
        static::__acting_as_user(self::USER_ID);
        $mine = static::__make(self::USER_ID);

        $result = Notification_Model::fetch($mine->id);

        static::__assert_true(is_array($result), 'the recipient reads it');
        static::__assert_equals($mine->id, $result['id'] ?? null);
    }

    public static function test_another_users_notification_is_not_found()
    {
        static::__acting_as_user(self::USER_ID);
        $theirs = static::__make(self::USER_ID + 1);

        static::__assert_false(Notification_Model::fetch($theirs->id), 'a foreign notification reads as missing');
        static::__assert_not_null(Notification_Model::find($theirs->id), 'and is untouched');
    }

    public static function test_an_invalid_notification_is_absent_but_not_deleted()
    {
        static::__acting_as_user(self::USER_ID);
        $client = new Client_Model();
        $client->name = 'Notification fetch probe ' . uniqid();
        $client->save();

        $stale = static::__make(self::USER_ID, $client);
        $client->forceDelete();

        static::__assert_false(Notification_Model::fetch($stale->id), 'an invalid notification reads as absent');
        static::__assert_not_null(Notification_Model::find($stale->id), 'the read deleted nothing');
    }

    private static function __make(int $login_user_id, ?object $entity = null): Notification_Model
    {
        $notification = new Notification_Model();
        $notification->site_id = 1;
        $notification->user_id = $login_user_id;
        $notification->type_id = array_key_first(Notification_Model::$enums['type_id']);
        if ($entity) {
            $notification->entity_type = class_basename($entity);
            $notification->entity_id = $entity->id;
        }
        $notification->expires_at = now()->addDays(1);
        $notification->save();

        return $notification;
    }
}
