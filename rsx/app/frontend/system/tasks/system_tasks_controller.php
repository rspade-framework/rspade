<?php

namespace Rsx\App\Frontend\System\Tasks;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Task\Task;

/**
 * System_Tasks_Controller - the Background Tasks screen's one action: start a run.
 *
 * Reading and controlling runs needs no controller here at all - the screen uses the
 * framework's Rsx_Task API and task widgets, which ask this application's task gates
 * (rsx/handlers/Task_Gate_Handlers.php). This endpoint only starts the showcase task, so the
 * signed-in user has a run of their own to watch.
 */
#[Auth('is_logged_in', 'can_manage_site_settings')]
class System_Tasks_Controller extends Rsx_Controller_Abstract
{
    /**
     * Dispatch Task_Showcase_Service::walk as the signed-in user.
     *
     * @return array {task_id}
     */
    #[Ajax_Endpoint]
    public static function start_showcase(Request $request, array $params = [])
    {
        return ['task_id' => Task::dispatch('Task_Showcase_Service', 'walk', ['items' => 60])];
    }
}
