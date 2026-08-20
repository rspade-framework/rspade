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
 * Service Test Service
 *
 * Test service for validating task execution system.
 */
class Service_Test_Service extends Rsx_Service_Abstract
{
    /**
     * Hello world test task
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters
     * @return array Result data
     */
    #[Task('Simple test task that returns hello world')]
    public static function hello_world(Task_Instance $task, array $params = []): array
    {
        $task->info('Hello world task executed');
        $task->debug('Debug message from hello_world task');

        $name = $params['name'] ?? 'World';

        return [
            'message' => "Hello, {$name}!",
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Test task with logging
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters
     * @return array Result data
     */
    #[Task_Attribute('Test task that demonstrates logging functionality')]
    public static function test_logging(Task_Instance $task, array $params = []): array
    {
        $task->info('Starting logging test');
        $task->debug('This is a debug message');
        $task->info('Processing step 1');
        $task->info('Processing step 2');
        $task->info('Logging test complete');

        return [
            'success' => true,
            'log_count' => 5,
        ];
    }
}
