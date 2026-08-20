<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Service\Rsx_Service_Abstract;

/**
 * RSX Task List Command
 * ===================
 *
 * Lists all available tasks organized by service
 */
class Task_List_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:task:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all available tasks';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $manifest = Manifest::get_all();
        $services = [];

        // Find all services and their tasks
        foreach ($manifest as $file_path => $info) {
            // Skip non-PHP files or files without classes
            if (!isset($info['class']) || !isset($info['fqcn'])) {
                continue;
            }

            // Check if class extends Rsx_Service_Abstract
            if (!isset($info['extends']) || $info['extends'] !== 'Rsx_Service_Abstract') {
                continue;
            }

            $service_name = $info['class'];
            $service_tasks = [];

            // Find methods with Task attribute
            if (isset($info['public_static_methods'])) {
                foreach ($info['public_static_methods'] as $method_name => $method_info) {
                    if (!isset($method_info['attributes'])) {
                        continue;
                    }

                    // Look for Task attribute
                    foreach ($method_info['attributes'] as $attr_name => $attr_instances) {
                        if ($attr_name === 'Task' || str_ends_with($attr_name, '\\Task')) {
                            // Extract description from first attribute instance
                            $description = '';
                            if (!empty($attr_instances) && isset($attr_instances[0]['arguments'][0])) {
                                $description = $attr_instances[0]['arguments'][0];
                            }

                            $service_tasks[] = [
                                'name' => $method_name,
                                'description' => $description
                            ];
                        }
                    }
                }
            }

            if (!empty($service_tasks)) {
                $services[$service_name] = $service_tasks;
            }
        }

        if (empty($services)) {
            $this->info('No tasks found.');
            return 0;
        }

        // Display tasks grouped by service
        $this->info('Available Tasks:');
        $this->newLine();

        foreach ($services as $service_name => $tasks) {
            $this->line("  <fg=cyan>{$service_name}</>");

            foreach ($tasks as $task) {
                $task_name = str_pad($task['name'], 25);
                $description = $task['description'] ?: '(no description)';
                $this->line("    <fg=green>{$task_name}</> - {$description}");
            }

            $this->newLine();
        }

        return 0;
    }
}
