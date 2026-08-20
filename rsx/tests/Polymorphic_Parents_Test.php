<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Models\Action_Log_Model;
use Rsx\Models\Action_Log_Related_Model;
use Rsx\Models\Client_Model;
use Rsx\Models\Notification_Model;
use Rsx\Models\Project_Model;
use Rsx\Models\Task_Model;

/**
 * The template app's polymorphic parent references, after the get_polymorphic_parent()
 * workaround was retired in favour of stock morphTo().
 *
 * Every one of these is a `{relation}_type` (BIGINT type ref) + `{relation}_id` pair read
 * through an ordinary Eloquent relation: Action_Log actor/subject, Action_Log_Related
 * related, Notification entity, Task taskable. What is being proven is that the relation
 * resolves the stored INTEGER discriminator to the right model - including a User_Model
 * parent, which lives in the framework namespace rather than the app one (the retired
 * hand-rolled resolver needed a special case for exactly that).
 *
 * Site-scoped models scope by the staff Session site_id, so seeding/querying runs under
 * __acting_as_site(). Default per-test transaction.
 */
class Polymorphic_Parents_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __make_client(): Client_Model
    {
        $client = new Client_Model();
        $client->site_id = self::SITE_ID;
        $client->name = 'Poly Client ' . uniqid();
        $client->save();

        return $client;
    }

    private static function __make_project(Client_Model $client): Project_Model
    {
        $project = new Project_Model();
        $project->site_id = self::SITE_ID;
        $project->client_id = $client->id;
        $project->name = 'Poly Project ' . uniqid();
        $project->save();

        return $project;
    }

    // =====================================================================
    // Action_Log - actor + subject
    // =====================================================================

    public static function test_action_log_subject_resolves_through_morph_to()
    {
        $client = static::__make_client();

        $log = new Action_Log_Model();
        $log->site_id = self::SITE_ID;
        $log->type_id = Action_Log_Model::TYPE_CLIENT_CREATED;
        $log->subject_type = 'Client_Model';
        $log->subject_id = $client->id;
        $log->save();

        $fresh = Action_Log_Model::find($log->id);

        static::__assert_instance_of(Client_Model::class, $fresh->subject);
        static::__assert_equals($client->id, $fresh->subject->id);
        static::__assert_equals($client->name, $fresh->subject_display());
    }

    public static function test_action_log_actor_is_null_for_a_system_action()
    {
        $client = static::__make_client();

        $log = new Action_Log_Model();
        $log->site_id = self::SITE_ID;
        $log->type_id = Action_Log_Model::TYPE_CLIENT_CREATED;
        $log->subject_type = 'Client_Model';
        $log->subject_id = $client->id;
        $log->save();

        $fresh = Action_Log_Model::find($log->id);

        static::__assert_null($fresh->actor, 'no actor pair means no actor');
        static::__assert_equals('System', $fresh->actor_display());
    }

    public static function test_action_log_actor_resolves_a_framework_namespace_model()
    {
        $user = User_Model::where('site_id', self::SITE_ID)->first();
        if (!$user) {
            static::__skip('No user row in this site to act as the log actor');

            return;
        }

        $client = static::__make_client();

        $log = new Action_Log_Model();
        $log->site_id = self::SITE_ID;
        $log->type_id = Action_Log_Model::TYPE_CLIENT_CREATED;
        $log->actor_type = 'User_Model';
        $log->actor_id = $user->id;
        $log->subject_type = 'Client_Model';
        $log->subject_id = $client->id;
        $log->save();

        static::__assert_instance_of(
            User_Model::class,
            Action_Log_Model::find($log->id)->actor,
            'the morph map resolves a framework-namespace model without any app-side special case'
        );
    }

    public static function test_action_log_related_resolves_through_morph_to()
    {
        $client = static::__make_client();
        $project = static::__make_project($client);

        $log = new Action_Log_Model();
        $log->site_id = self::SITE_ID;
        $log->type_id = Action_Log_Model::TYPE_PROJECT_CREATED;
        $log->subject_type = 'Project_Model';
        $log->subject_id = $project->id;
        $log->save();

        $related = new Action_Log_Related_Model();
        $related->action_log_id = $log->id;
        $related->role_id = Action_Log_Related_Model::ROLE_TARGET;
        $related->related_type = 'Client_Model';
        $related->related_id = $client->id;
        $related->save();

        $fresh = Action_Log_Related_Model::find($related->id);

        static::__assert_instance_of(Client_Model::class, $fresh->related);
        static::__assert_equals($client->id, $fresh->related->id);
    }

    // =====================================================================
    // Notification - entity
    // =====================================================================

    public static function test_notification_entity_resolves_through_morph_to()
    {
        $user = User_Model::where('site_id', self::SITE_ID)->first();
        if (!$user) {
            static::__skip('No user row in this site to address the notification to');

            return;
        }

        $client = static::__make_client();

        $notification = new Notification_Model();
        $notification->site_id = self::SITE_ID;
        $notification->user_id = $user->id;
        $notification->type_id = Notification_Model::TYPE_CLIENT_CREATED;
        $notification->entity_type = 'Client_Model';
        $notification->entity_id = $client->id;
        $notification->expires_at = \App\RSpade\Core\Time\Rsx_Time::add(\App\RSpade\Core\Time\Rsx_Time::now_iso(), 86400);
        $notification->save();

        $fresh = Notification_Model::find($notification->id);

        static::__assert_instance_of(Client_Model::class, $fresh->entity);
        static::__assert_true($fresh->is_valid(), 'a notification whose entity still exists is valid');
    }

    public static function test_notification_without_an_entity_is_valid_and_has_no_entity()
    {
        $user = User_Model::where('site_id', self::SITE_ID)->first();
        if (!$user) {
            static::__skip('No user row in this site to address the notification to');

            return;
        }

        $notification = new Notification_Model();
        $notification->site_id = self::SITE_ID;
        $notification->user_id = $user->id;
        $notification->type_id = Notification_Model::TYPE_CLIENT_CREATED;
        $notification->expires_at = \App\RSpade\Core\Time\Rsx_Time::add(\App\RSpade\Core\Time\Rsx_Time::now_iso(), 86400);
        $notification->save();

        $fresh = Notification_Model::find($notification->id);

        static::__assert_null($fresh->entity);
        static::__assert_true($fresh->is_valid());
    }

    // =====================================================================
    // Task - taskable (and the realtime touch cascade that reads it)
    // =====================================================================

    public static function test_task_taskable_resolves_a_project_parent()
    {
        $project = static::__make_project(static::__make_client());

        $task = new Task_Model();
        $task->site_id = self::SITE_ID;
        $task->title = 'Poly Task ' . uniqid();
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->taskable_type = 'Project_Model';
        $task->taskable_id = $project->id;
        $task->save();

        $fresh = Task_Model::find($task->id);

        static::__assert_instance_of(Project_Model::class, $fresh->taskable);
        static::__assert_equals($project->id, $fresh->taskable->id);
    }

    public static function test_task_taskable_resolves_a_framework_namespace_user_parent()
    {
        $user = User_Model::where('site_id', self::SITE_ID)->first();
        if (!$user) {
            static::__skip('No user row in this site to own the task');

            return;
        }

        $task = new Task_Model();
        $task->site_id = self::SITE_ID;
        $task->title = 'Poly Task ' . uniqid();
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->taskable_type = 'User_Model';
        $task->taskable_id = $user->id;
        $task->save();

        static::__assert_instance_of(
            User_Model::class,
            Task_Model::find($task->id)->taskable,
            'a User_Model parent resolves through the morph map, no namespace special-casing'
        );
    }

    public static function test_task_realtime_touch_returns_the_polymorphic_parent()
    {
        $project = static::__make_project(static::__make_client());

        $task = new Task_Model();
        $task->site_id = self::SITE_ID;
        $task->title = 'Poly Task ' . uniqid();
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->taskable_type = 'Project_Model';
        $task->taskable_id = $project->id;
        $task->save();

        $touched = Task_Model::find($task->id)->realtime_touch();

        static::__assert_count(1, $touched);
        static::__assert_instance_of(Project_Model::class, $touched[0]);
    }

    public static function test_task_realtime_touch_is_empty_without_a_parent()
    {
        $task = new Task_Model();
        $task->site_id = self::SITE_ID;
        $task->title = 'Poly Task ' . uniqid();
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->save();

        static::__assert_count(0, Task_Model::find($task->id)->realtime_touch());
    }
}
