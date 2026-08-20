<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\CodeTemplates\StubProcessor;

class Module_Create_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:app:module:create
                            {name : Module name (lowercase with underscores)}
                            {--blade : Scaffold a server-rendered Blade module instead of an SPA module}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new module. Default is an SPA module (bootstrap + layout + index Action); --blade scaffolds a server-rendered Blade module for public/SEO pages.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $module_name = $this->argument('name');
        $blade_mode = (bool) $this->option('blade');

        // Validate module name (lowercase and underscores only)
        if (!preg_match('/^[a-z_]+$/', $module_name)) {
            $this->error('Module name must contain only lowercase letters and underscores.');
            return 1;
        }

        // Check if module already exists
        $module_path = base_path("rsx/app/{$module_name}");
        if (is_dir($module_path)) {
            $this->error("Module '{$module_name}' already exists at: rsx/app/{$module_name}");
            return 1;
        }

        // Create module directory
        if (!mkdir($module_path, 0755, true)) {
            $this->error("Failed to create module directory: {$module_path}");
            return 1;
        }

        $this->info("Created module directory: rsx/app/{$module_name}");

        try {
            if ($blade_mode) {
                $result = $this->create_blade_module($module_name, $module_path);
            } else {
                $result = $this->create_spa_module($module_name, $module_path);
            }

            if ($result !== 0) {
                $this->cleanup_directory($module_path);
                return $result;
            }
        } catch (\Exception $e) {
            $this->error("Failed to create module: " . $e->getMessage());
            // Clean up on failure
            if (is_dir($module_path)) {
                $this->cleanup_directory($module_path);
            }
            return 1;
        }

        return 0;
    }

    /**
     * Scaffold an SPA module: bundle + #[SPA] bootstrap + persistent layout + index Action.
     */
    protected function create_spa_module(string $module_name, string $module_path): int
    {
        $replacements = StubProcessor::generate_spa_replacements($module_name);

        // Bundle (module bundle - pulls in the module directory via __DIR__)
        $bundle_file = "{$module_path}/{$module_name}_bundle.php";
        file_put_contents_safe($bundle_file, StubProcessor::process('spa_bundle', $replacements));
        $this->info("Created bundle: {$module_name}_bundle.php");

        // SPA bootstrap controller
        $controller_file = "{$module_path}/{$module_name}_spa_controller.php";
        file_put_contents_safe($controller_file, StubProcessor::process('spa_controller', $replacements));
        $this->info("Created SPA bootstrap: {$module_name}_spa_controller.php");

        // Persistent layout (JS + template)
        $layout_class = $replacements['spa_layout_class'];
        file_put_contents_safe("{$module_path}/{$layout_class}.js", StubProcessor::process('spa_layout', $replacements));
        $this->info("Created layout: {$layout_class}.js");
        file_put_contents_safe("{$module_path}/{$layout_class}.jqhtml", StubProcessor::process('spa_layout_template', $replacements));
        $this->info("Created layout template: {$layout_class}.jqhtml");

        // Default index feature (its own directory, like every other SPA feature)
        $feature_result = $this->call('rsx:app:module:feature:create', [
            'module' => $module_name,
            'feature' => 'index',
        ]);

        if ($feature_result !== 0) {
            return $feature_result;
        }

        $this->info("[OK] SPA module '{$module_name}' created successfully!");
        $this->info("Route: {$replacements['route_path']}");
        $this->info("Bootstrap: {$replacements['spa_controller_class']}");
        $this->info("Layout: {$layout_class}");
        $this->line('');
        $this->line('Next steps:');
        $this->line("  Add a screen:   php artisan rsx:app:module:feature:create {$module_name} <feature>");
        $this->line('  SPA routing:    php artisan rsx:man spa');
        $this->line('  CRUD shape:     php artisan rsx:man crud');

        return 0;
    }

    /**
     * Scaffold a server-rendered Blade module (public / SEO pages).
     */
    protected function create_blade_module(string $module_name, string $module_path): int
    {
        $replacements = StubProcessor::generate_replacements($module_name);

        // Create bundle file
        $bundle_content = StubProcessor::process('bundle', $replacements);
        $bundle_path = "{$module_path}/{$module_name}_bundle.php";
        file_put_contents_safe($bundle_path, $bundle_content);
        $this->info("Created bundle: {$module_name}_bundle.php");

        // Create layout file
        $layout_content = StubProcessor::process('module_layout', $replacements);
        $layout_path = "{$module_path}/{$module_name}_layout.blade.php";
        file_put_contents_safe($layout_path, $layout_content);
        $this->info("Created layout: {$module_name}_layout.blade.php");

        // Create default index feature
        $feature_result = $this->call('rsx:app:module:feature:create', [
            'module' => $module_name,
            'feature' => 'index',
            '--blade' => true,
        ]);

        if ($feature_result !== 0) {
            return $feature_result;
        }

        $this->info("[OK] Blade module '{$module_name}' created successfully!");
        $this->info("Route: /{$module_name}");
        $this->line('');
        $this->line('Blade modules are for public / server-rendered pages (login, marketing, SEO).');
        $this->line('Authenticated app areas belong in an SPA module (drop --blade).');
        $this->line('  Module ladder:  php artisan rsx:man module_organization');

        return 0;
    }

    /**
     * Clean up directory on failure
     */
    protected function cleanup_directory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->cleanup_directory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
