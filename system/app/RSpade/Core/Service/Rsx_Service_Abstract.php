<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Service;

use App\RSpade\Core\Task\Task_Instance;

/**
 * Base service class for all RSX services
 *
 * Services house background tasks that can be executed via CLI, queued, or scheduled.
 * All RSX services should extend this class and use standard OOP patterns.
 * Tasks are defined using #[Task] attributes on static methods.
 */
#[Monoprogenic]
abstract class Rsx_Service_Abstract
{
    /**
     * Pre-task hook called before any task execution
     * Override in child classes to add pre-task logic
     *
     * @param Task_Instance $task Task instance for logging and status tracking
     * @param array $params Task parameters
     * @return mixed|null Return null to continue, or throw exception to halt
     */
    public static function pre_task(Task_Instance $task, array $params = [])
    {
        // Default implementation does nothing
        // Override in child classes to add authentication, validation, logging, etc.
        return null;
    }

    /**
     * Get a parameter value with optional default
     *
     * @param array $params Parameters array
     * @param string $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    protected static function __param($params, $key, $default = null)
    {
        return $params[$key] ?? $default;
    }

    /**
     * Check if a parameter exists
     *
     * @param array $params Parameters array
     * @param string $key Parameter key
     * @return bool
     */
    protected static function __has_param($params, $key)
    {
        return isset($params[$key]);
    }
}
