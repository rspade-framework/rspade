<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Services;

use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Scheduled Test Service
 *
 * Test service with scheduled tasks for testing the scheduling system.
 */
class Scheduled_Test_Service extends Rsx_Service_Abstract
{
    /**
     * Test task that runs every minute
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters
     * @return array Result data
     */
    #[Task_Attribute('Test scheduled task that runs every minute')]
    #[Schedule('* * * * *', 'default')]
    public static function every_minute(Task_Instance $task, array $params = []): array
    {
        $task->info('Every minute task executed at ' . date('Y-m-d H:i:s'));

        return [
            'executed_at' => date('Y-m-d H:i:s'),
            'message' => 'Every minute task completed',
        ];
    }

    /**
     * Test task that runs every 5 minutes
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters
     * @return array Result data
     */
    #[Task_Attribute('Test scheduled task that runs every 5 minutes')]
    #[Schedule('*/5 * * * *', 'default')]
    public static function every_five_minutes(Task_Instance $task, array $params = []): array
    {
        $task->info('Every 5 minutes task executed at ' . date('Y-m-d H:i:s'));

        return [
            'executed_at' => date('Y-m-d H:i:s'),
            'message' => 'Every 5 minutes task completed',
        ];
    }

    /**
     * Test task that runs daily at midnight
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters
     * @return array Result data
     */
    #[Task_Attribute('Test scheduled task that runs daily at midnight')]
    #[Schedule('0 0 * * *', 'scheduled')]
    public static function daily_midnight(Task_Instance $task, array $params = []): array
    {
        $task->info('Daily midnight task executed at ' . date('Y-m-d H:i:s'));

        return [
            'executed_at' => date('Y-m-d H:i:s'),
            'message' => 'Daily midnight task completed',
        ];
    }
}
