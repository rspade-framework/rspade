<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Lib\Notification\Notification;
use Rsx\Models\Notification_Model;

/**
 * Notification's writer and readers agree on who the recipient is: notifications.user_id
 * holds a LOGIN identity id (login_users.id), and every read matches
 * Session::get_login_user_id() - the same test Notification_Model::fetch() applies.
 *
 * The seeded first user has users.id == login_users.id == 1, which hides a reader keyed on
 * the wrong id. So the subject is a fresh member whose site-user id is forced to differ from
 * its login id, and a decoy row filed under the SITE-user id proves the readers ignore it.
 *
 * Runs in the default per-test transaction (rolled back afterward).
 */
class Notification_Recipient_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function test_the_readers_find_what_send_wrote()
    {
        $user = static::__member_with_diverging_ids();
        static::__acting_as_user($user->id);

        static::__assert_equals((int) $user->login_user_id, Session::get_login_user_id());
        static::__assert_not_equals((int) $user->id, (int) $user->login_user_id, 'precondition: the two ids differ');

        [$sent] = Notification::send(
            Notification_Model::TYPE_TASK_ASSIGNED,
            [(int) $user->login_user_id],
            null,
            ['task_title' => 'Recipient probe', 'project_name' => 'Recipient probe']
        );

        // A decoy filed under the site-user id: no reader may pick it up.
        $decoy = new Notification_Model();
        $decoy->site_id = self::SITE_ID;
        $decoy->user_id = (int) $user->id;
        $decoy->type_id = $sent->type_id;
        $decoy->expires_at = now()->addDays(1);
        $decoy->save();

        static::__assert_equals(1, Notification::get_unread_count(), 'the unread count sees the sent row only');

        $dropdown = Notification::get_for_dropdown(5);
        static::__assert_equals(1, $dropdown['total'], 'the dropdown lists the sent row only');
        static::__assert_equals($sent->id, $dropdown['notifications'][0]['id'] ?? null);

        static::__assert_true(is_array(Notification_Model::fetch($sent->id)), 'fetch() serves the recipient');
        static::__assert_false(Notification_Model::fetch($decoy->id), 'fetch() refuses the decoy');

        static::__assert_true(Notification::mark_read($sent->id), 'mark_read() finds the recipient\'s row');
        static::__assert_false(Notification::mark_read($decoy->id), 'mark_read() refuses the decoy');
        static::__assert_equals(0, Notification::get_unread_count());
        static::__assert_null(Notification_Model::find($decoy->id)->read_at, 'the decoy was left unread');
    }

    /**
     * A site member whose users.id is explicitly placed above its login_users.id, so a reader
     * keyed on the wrong identity cannot pass by coincidence.
     */
    private static function __member_with_diverging_ids(): User_Model
    {
        $email = 'notification_recipient_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = 'not-a-login-path';
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $highest_user_id = (int) User_Model::without_site_scope(fn () => User_Model::withTrashed()->max('id'));

        $user = new User_Model();
        $user->id = max($highest_user_id, (int) $login_user->id) + 1;
        $user->site_id = self::SITE_ID;
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Notification';
        $user->last_name = 'Recipient';
        $user->role_id = User_Model::ROLE_VIEWER;
        $user->is_enabled = true;
        $user->save();

        return $user;
    }
}
