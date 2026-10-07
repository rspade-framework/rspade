<?php

namespace Rsx\Handlers;

use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Session\Session;

/**
 * Task_Gate_Handlers - who may watch and stop background task runs.
 *
 * The framework's task gates DENY by default: with no handler, nobody but a developer may
 * see a run or act on one (Task_Gates). This application's policy: a staff user may see the
 * runs THEY started (Task_Run_Model::dispatched_by is their User_Model), and may stop, cancel
 * or rerun those runs - never force-kill one; killing a worker is a developer's call, made in
 * the /_sys console. Portal users get no handlers here, so the portal realm stays closed.
 *
 * Every rule appears twice and the two must agree: the GATE answers for one run, the SCOPE
 * narrows a list of runs to the same set.
 */
class Task_Gate_Handlers
{
    /** The control actions a user may take on a run they started. */
    const OWN_RUN_ACTIONS = ['stop', 'force_stop', 'cancel', 'rerun'];

    /**
     * One run: visible when the signed-in user started it.
     *
     * @param array $data {task: Task_Run_Model, user: ?User_Model}
     */
    #[OnEvent('task.view.authorize', priority: 10)]
    public static function view_own_runs($data)
    {
        return static::__started_by_current_user($data['task']);
    }

    /**
     * A list of runs: narrowed to those the signed-in user started.
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
            ->where('dispatched_by_id', (int) $user_id);
    }

    /**
     * An action on one run: stop, force stop, cancel or rerun a run the user started.
     *
     * @param array $data {task, user, action}
     */
    #[OnEvent('task.control.authorize', priority: 10)]
    public static function control_own_runs($data)
    {
        return in_array($data['action'], self::OWN_RUN_ACTIONS, true)
            && static::__started_by_current_user($data['task']);
    }

    private static function __started_by_current_user($task): bool
    {
        $user_id = Session::get_user_id();

        return $user_id
            && $task->dispatched_by_type === 'User_Model'
            && (int) $task->dispatched_by_id === (int) $user_id;
    }
}
