<?php

namespace Rsx\Handlers;

use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;

/**
 * Task_Gate_Handlers - who may watch and act on background task runs.
 *
 * The framework's task gates DENY by default: with no handler, nobody but a developer may
 * see a run or act on one (Task_Gates). This file grants the least this application's screens
 * need, the way an AWS policy.json grants only the actions a role was built to perform:
 *
 *   WHO     staff users (User_Model) - and only on the runs THEY started. The portal gets no
 *           handlers, so the portal realm stays closed.
 *   WHAT    only the tasks named in USER_TASKS - the ones a screen of this application shows a
 *           user. Any other task's runs stay invisible to users, even ones they started; the
 *           /_sys console shows those to developers.
 *   ACTIONS per task, only the actions that screen offers. force_kill is never granted:
 *           killing a worker is a developer's call, made in the /_sys console.
 *
 * WHEN YOU ADD OR CHANGE A TASK that users should see, add (or narrow) its entry here in the
 * same change - never a wildcard, never an action the screen does not offer.
 *
 * Every rule appears twice and the two must agree: the GATE answers for one run, the SCOPE
 * narrows a list of runs to the same set.
 */
class Task_Gate_Handlers
{
    /**
     * The tasks a user may see (the runs they started), each with the actions they may take.
     * Keyed 'Service::method' (simple service name).
     */
    const USER_TASKS = [
        // The Background Tasks screen's showcase (rsx/app/frontend/system/tasks/).
        'Task_Showcase_Service::walk' => ['stop', 'force_stop', 'cancel', 'rerun'],
    ];

    /**
     * One run: visible when the signed-in user started it and its task is listed.
     *
     * @param array $data {task: Task_Run_Model, user: ?User_Model}
     */
    #[OnEvent('task.view.authorize', priority: 10)]
    public static function view_own_runs($data)
    {
        return static::__allowed_actions($data['task']) !== null;
    }

    /**
     * A list of runs: narrowed to the listed tasks' runs the signed-in user started.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    #[OnEvent('task.view.scope', priority: 10)]
    public static function scope_own_runs($query)
    {
        $user_id = Session::get_user_id();
        if (!$user_id) {
            return $query->whereRaw('0 = 1');
        }

        return $query
            ->where('dispatched_by_type', Type_Ref_Registry::class_to_id('User_Model'))
            ->where('dispatched_by_id', (int) $user_id)
            ->where(function ($tasks) {
                foreach (array_keys(self::USER_TASKS) as $key) {
                    [$service, $method] = explode('::', $key, 2);
                    $tasks->orWhere(function ($one) use ($service, $method) {
                        $one->where('class', Task::resolve_task_class($service, $method))->where('method', $method);
                    });
                }
            });
    }

    /**
     * An action on one run: allowed when the user may see the run and its task's entry
     * grants that action.
     *
     * @param array $data {task, user, action}
     */
    #[OnEvent('task.control.authorize', priority: 10)]
    public static function control_own_runs($data)
    {
        $actions = static::__allowed_actions($data['task']);

        return $actions !== null && in_array($data['action'], $actions, true);
    }

    /**
     * The actions the signed-in user may take on this run, or null when they may not see it:
     * they did not start it, or its task is not listed.
     */
    private static function __allowed_actions($task): ?array
    {
        $user_id = Session::get_user_id();
        if (!$user_id || $task->dispatched_by_type !== 'User_Model' || (int) $task->dispatched_by_id !== (int) $user_id) {
            return null;
        }

        return self::USER_TASKS[class_basename($task->class) . '::' . $task->method] ?? null;
    }
}
