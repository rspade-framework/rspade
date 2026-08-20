<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;

class Component_Create_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:app:component:create
                            {--name= : Component name (lowercase with underscores, must end in _component)}
                            {--path= : Target directory path (e.g., rsx/theme/components)}
                            {--module= : Module name to create component in}
                            {--feature= : Feature name within module (requires --module)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new jqhtml component with template and JavaScript class';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get and validate component name
        $component_name = $this->option('name');

        if (!$component_name) {
            $this->error('Component name is required. Use --name=my_widget_component');
            $this->info('');
            $this->info('Usage examples:');
            $this->info('  php artisan rsx:app:component:create --name=my_widget_component --path=rsx/theme/components');
            $this->info('  php artisan rsx:app:component:create --name=user_card_component --module=dashboard');
            $this->info('  php artisan rsx:app:component:create --name=form_field_component --module=backend --feature=users');
            return 1;
        }

        // Validate component name format
        if (!preg_match('/^[a-z_]+_component$/', $component_name)) {
            $this->error('Component name must be lowercase with underscores and end with "_component"');
            $this->info('Example: my_widget_component, user_card_component, form_field_component');
            return 1;
        }

        // Determine target directory
        $target_dir = $this->determine_target_directory();

        if (!$target_dir) {
            return 1; // Error already displayed
        }

        // Create directory if it doesn't exist
        if (!is_dir($target_dir)) {
            if (!mkdir($target_dir, 0755, true)) {
                $this->error("Failed to create directory: {$target_dir}");
                return 1;
            }
            $this->info("Created directory: {$target_dir}");
        }

        // Generate class name (uppercase with underscores)
        $class_name = $this->to_class_name($component_name);

        // Check if files already exist
        $jqhtml_file = "{$target_dir}/{$component_name}.jqhtml";
        $js_file = "{$target_dir}/{$component_name}.js";

        if (file_exists($jqhtml_file)) {
            $this->error("Component template already exists: {$jqhtml_file}");
            return 1;
        }

        if (file_exists($js_file)) {
            $this->error("Component JavaScript already exists: {$js_file}");
            return 1;
        }

        // Generate and write jqhtml template
        $jqhtml_content = $this->generate_jqhtml_content($class_name);
        file_put_contents_safe($jqhtml_file, $jqhtml_content);
        $this->info("Created template: {$component_name}.jqhtml");

        // Generate and write JavaScript class
        $js_content = $this->generate_js_content($class_name);
        file_put_contents_safe($js_file, $js_content);
        $this->info("Created JavaScript: {$component_name}.js");

        // Show success message with usage examples
        $this->show_success_message($class_name, $component_name, $target_dir);

        return 0;
    }

    /**
     * Determine the target directory based on options
     */
    protected function determine_target_directory()
    {
        $path = $this->option('path');
        $module = $this->option('module');
        $feature = $this->option('feature');

        // Check for conflicting options
        if ($path && ($module || $feature)) {
            $this->error('Cannot use --path with --module or --feature options');
            return null;
        }

        // If feature is specified, module is required
        if ($feature && !$module) {
            $this->error('--feature requires --module to be specified');
            return null;
        }

        // Determine directory based on options
        if ($path) {
            // Direct path specified
            return base_path($path);
        } elseif ($module) {
            // Module specified
            $module_dir = base_path("rsx/app/{$module}");

            // Check if module exists
            if (!is_dir($module_dir)) {
                $this->error("Module '{$module}' does not exist at: rsx/app/{$module}");
                $this->info("Create the module first with: php artisan rsx:app:module:create {$module}");
                return null;
            }

            if ($feature) {
                // Feature within module
                return "{$module_dir}/{$feature}/components";
            } else {
                // Module root components directory
                return "{$module_dir}/components";
            }
        } else {
            // No options specified - prompt for location
            $this->error('No target location specified. Please use one of the following options:');
            $this->info('');
            $this->info('Option 1: Specify a path directly');
            $this->info('  --path=rsx/theme/components  (for application-wide components)');
            $this->info('');
            $this->info('Option 2: Create in a module');
            $this->info('  --module=dashboard  (creates in rsx/app/dashboard/components/)');
            $this->info('');
            $this->info('Option 3: Create in a module feature');
            $this->info('  --module=backend --feature=users  (creates in rsx/app/backend/users/components/)');
            $this->info('');
            $this->info('Recommendation: Use --path=rsx/theme/components for components that should be');
            $this->info('accessible to the entire application.');
            return null;
        }
    }

    /**
     * Convert component name to class name format
     */
    protected function to_class_name($name)
    {
        $parts = explode('_', $name);
        return implode('_', array_map('ucfirst', $parts));
    }

    /**
     * Generate jqhtml template content
     */
    protected function generate_jqhtml_content($class_name)
    {
        // Remove '_Component' suffix for display title
        $display_name = str_replace('_Component', '', $class_name);

        return <<<JQHTML
<%--
{$class_name}

\$button_text="Click Me" - Text shown on the button
--%>
<Define:{$class_name} style="border: 1px solid black;">
    <h4>{$display_name}</h4>
    <div \$id="hello_world" style="font-weight: bold; display: none;">
        <% for (let i = 0; i < 2; i++): %>
            Hello,
        <% endfor; %>
        World!
    </div>
    <div \$id="inner_html">
        <%= content() %>
    </div>
    <button @click=this.on_click_hello>
        <%= this.args.button_text || "Click Me" %>
    </button>
</Define:{$class_name}>
JQHTML;
    }

    /**
     * Generate JavaScript class content
     */
    protected function generate_js_content($class_name)
    {
        return <<<JS
/**
 * {$class_name} - JQHTML Component
 *
 * Lifecycle methods are called in this order:
 * 1. on_create() - Quick UI setup, runs bottom-up through component tree
 * 2. on_load() - Fetch data from APIs (parallel execution, no DOM modifications)
 * 3. on_ready() - Component fully initialized, runs bottom-up through component tree
 */
class {$class_name} extends Component {
    /**
     * Called after render, quick UI setup (bottom-up)
     * Use for: Initial state, event bindings, showing loading indicators
     */
    async on_create() {
        // Example: this.\$id('loading').show();
        // Example: this.\$.addClass('initializing');
    }

    /**
     * Fetch data from APIs (parallel, NO DOM modifications)
     * Use for: Loading data from server, fetching configurations
     * WARNING: Do NOT modify DOM here - only load data
     */
    async on_load() {
        // Example: this.data.users = await Users_Controller.get_users_api();
        // Example: this.data.config = await this.load_config();
        // WARNING: Do NOT modify DOM here - only load data
    }

    /**
     * Component fully initialized (bottom-up)
     * Use for: Final UI setup, hiding loading indicators, starting animations
     */
    async on_ready() {
        // Example: this.\$id('loading').hide();
        // Example: this.setup_event_listeners();
    }

    /**
     * Click handler for the hello button
     * Referenced in template via @click=this.on_click_hello
     */
    on_click_hello() {
        this.\$id('inner_html').hide();
        this.\$id('hello_world').show();
    }

    // For more information: php artisan rsx:man jqhtml
}
JS;
    }

    /**
     * Show success message with usage examples
     */
    protected function show_success_message($class_name, $component_name, $target_dir)
    {
        // Calculate relative path from project root
        $relative_path = str_replace(base_path() . '/', '', $target_dir);

        $this->info("");
        $this->info("[OK] Component '{$class_name}' created successfully!");
        $this->info("Location: {$relative_path}/");
        $this->info("");
        $this->info("Usage Examples:");
        $this->info("");
        $this->info("1. Include in a Blade template:");
        $this->info("   @component('{$class_name}', ['button_text' => 'Submit'])");
        $this->info("       <p>This content goes inside the component</p>");
        $this->info("   @endcomponent");
        $this->info("");
        $this->info("2. Create dynamically with jQuery:");
        $this->info("   \$('#my-container').component('{$class_name}', {");
        $this->info("       button_text: 'Submit'");
        $this->info("   });");
        $this->info("");
        $this->info("3. Include in another jqhtml template:");
        $this->info("   <{$class_name} button_text=\"Submit\">");
        $this->info("       <p>Inner content here</p>");
        $this->info("   </{$class_name}>");
        $this->info("");
        $this->info("4. Access existing component instance:");
        $this->info("   const component = \$('#my-container').component();");
        $this->info("   component.on_click_hello(); // Call component methods");
        $this->info("");
        $this->info("Learn more: php artisan rsx:man jqhtml");
    }
}