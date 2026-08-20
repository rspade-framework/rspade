<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Rsx_Bundle_Provider - Registers Blade directives and precompilers for RSX views
 *
 * REGISTERED IN: config/app.php line ~213 in 'providers' array
 *
 * WHAT IT DOES:
 * 1. Registers Blade directives:
 *    - @rsxBundle('name') - Renders RSX JavaScript bundle placeholder
 *    - @csrf - Overrides Laravel's @csrf to use Session::get_csrf_token()
 *    - @rsx_id('unique.id') - Metadata directive for path-agnostic views
 *    - @rsx_include('partial.id') - Includes partials by RSX ID instead of path
 *
 * 2. Registers @rsx_extends precompiler:
 *    - Converts @rsx_extends('parent.id') to @extends with resolved view path
 *    - Resolves RSX view IDs from manifest at compile time
 *    - Supports passing variables to parent layout
 *    - Runs BEFORE Blade's native compilation
 *
 * PURPOSE:
 * Enables path-agnostic view system where views are referenced by ID rather
 * than file paths, making code resilient to file reorganization.
 */
#[Instantiatable]
class Rsx_Bundle_Provider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // No registrations needed - RSX JavaScript compiler is used directly
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Register RSX extends precompiler (must be before directives)
        $this->registerRsxExtendsPrecompiler();

        // Register Blade directives
        $this->registerBladeDirectives();

        // Removed code that makes manifest load here... did we need it?
        // Manifest::init();
    }

    /**
     * Register Blade directives
     */
    protected function registerBladeDirectives(): void
    {
        // @rsxBundle('frontend') - Render RSX JavaScript bundle
        Blade::directive('rsxBundle', function ($expression) {
            $expression = $expression ?: "'frontend'";

            // This directive would need implementation to render the compiled bundle
            return "<?php echo '<!-- RSX Bundle: ' . {$expression} . ' -->'; ?>";
        });

        // Override Laravel's @csrf to use our custom session implementation.
        //
        // ONE TOKEN, BOTH EXPERIENCES: a browser has one session row and one
        // csrf_token, and Rsx_Csrf verifies against that same row on staff and portal
        // POSTs alike - so this directive has nothing to fork on. (It briefly did,
        // when the portal had a session row and a cookie of its own.) Fully qualified
        // so the compiled view never depends on a namespace alias.
        Blade::directive('csrf', function () {
            return '<?php $csrf_token = \\App\\RSpade\\Core\\Session\\Session::get_csrf_token();'
                . ' if ($csrf_token !== null) { echo \'<input type="hidden" name="_csrf_token" value="\' . $csrf_token . \'">\'; } ?>';
        });

        // RSX Path-Agnostic Blade Directives

        // @rsx_id('unique.id') - Define a path-agnostic identifier for this view
        Blade::directive('rsx_id', function ($expression) {
            // This directive is for metadata only, processed by manifest
            return "<?php /* RSX ID: {$expression} */ ?>";
        });

        // @rsx_page_data(['key' => 'value']) - Add data to window.rsxapp.page_data
        Blade::directive('rsx_page_data', function ($expression) {
            return "<?php \\App\\RSpade\\Core\\View\\PageData::add({$expression}); ?>";
        });

        // @rsx_extends is handled by the precompiler, not as a directive
        // The precompiler converts @rsx_extends('parent.id') to @extends($resolved_path)
        // @rsx_include('partial.id') - Include a partial view by RSX ID
        Blade::directive('rsx_include', function ($expression) {
            // Parse expression to handle optional data parameter
            if (strpos($expression, ',') !== false) {
                list($id, $data) = explode(',', $expression, 2);
                $id = trim($id);
                $data = trim($data);
            } else {
                $id = $expression;
                $data = '[]';
            }

            // Output the PHP code that @include would generate
            return "<?php echo \$__env->make(rsx_view_path({$id}), {$data}, \\Illuminate\\Support\\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>";
        });
    }

    /**
     * Register RSX extends precompiler to handle @rsx_extends before Blade compilation
     */
    protected function registerRsxExtendsPrecompiler(): void
    {
        // Get the Blade compiler instance
        $blade = app('blade.compiler');

        // Add a precompiler that converts @rsx_extends to @extends with resolved path
        $blade->precompiler(function ($value) {
            // Match @rsx_extends with optional second parameter for variables
            // Pattern matches both @rsx_extends('id') and @rsx_extends('id', [...])
            // The 's' modifier allows . to match newlines for multiline arrays
            $pattern = '/@rsx_extends\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*(.+?))?\s*\)/s';

            return preg_replace_callback($pattern, function ($matches) {
                $rsx_id = $matches[1];
                $variables_expr = isset($matches[2]) ? $matches[2] : null;

                // Get the manifest to resolve the RSX ID at compile time
                $file_path = \App\RSpade\Core\Manifest\Manifest::find_view_by_rsx_id($rsx_id);

                if (!$file_path) {
                    throw new RuntimeException("RSX view with ID '{$rsx_id}' not found in manifest");
                }

                // Convert to Laravel view path format
                $view_path = preg_replace('/\.blade\.php$/', '', $file_path);
                if (str_starts_with($view_path, 'rsx/')) {
                    // Remove 'rsx/' prefix and convert to dot notation
                    $view_path = substr($view_path, 4);
                    $view_path = str_replace('/', '.', $view_path);
                    // Use namespace separator for RSX views
                    $view_path = 'rsx::' . $view_path;
                }

                // If variables are provided, inject them before extending
                if ($variables_expr) {
                    $code = "<?php\n";
                    $code .= "// Variables passed from child view to layout\n";
                    $code .= "\$__rsx_layout_vars = {$variables_expr};\n";
                    $code .= "foreach (\$__rsx_layout_vars as \$__rsx_key => \$__rsx_value) {\n";
                    $code .= "    \$\$__rsx_key = \$__rsx_value;\n";
                    $code .= "}\n";
                    $code .= "unset(\$__rsx_layout_vars, \$__rsx_key, \$__rsx_value);\n";
                    $code .= "?>\n";
                    $code .= "@extends('{$view_path}')";

                    return $code;
                }

                // Return Laravel's native @extends directive without variables
                return "@extends('{$view_path}')";
            }, $value);
        });
    }
}
