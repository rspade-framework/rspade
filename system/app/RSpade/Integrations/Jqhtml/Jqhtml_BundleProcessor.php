<?php

namespace App\RSpade\Integrations\Jqhtml;

use Exception;
use RuntimeException;
use App\RSpade\Core\Bundle\BundleProcessor_Abstract;
use App\RSpade\Integrations\Jqhtml\JqhtmlWebpackCompiler;
use App\RSpade\Integrations\Jqhtml\Jqhtml_Exception_ViewException;

/**
 * JqhtmlProcessor - Processes JQHTML template files
 *
 * Compiles JQHTML templates into JavaScript code that can be
 * included in bundles. Uses webpack-based compilation with caching.
 */
class Jqhtml_BundleProcessor extends BundleProcessor_Abstract
{
    /**
     * Compiler instance
     */
    protected static ?JqhtmlWebpackCompiler $compiler = null;

    /**
     * Get processor name
     */
    public static function get_name(): string
    {
        return 'jqhtml';
    }

    /**
     * Get file extensions this processor handles
     */
    public static function get_extensions(): array
    {
        return ['jqhtml'];
    }

    /**
     * Process multiple files in batch
     * Compiles JQHTML templates and appends the JavaScript output to the bundle
     */
    public static function process_batch(array &$bundle_files): void
    {
        // Check for jqhtml files
        $jqhtml_files = array_filter($bundle_files, function ($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'jqhtml';
        });

        console_debug('JQHTML', 'process_batch called with ' . count($bundle_files) . ' files, ' . count($jqhtml_files) . ' jqhtml files');

        if (empty($jqhtml_files)) {
            console_debug('JQHTML', 'No jqhtml files to process');

            return;
        }

        // Initialize compiler if needed
        if (!static::$compiler) {
            static::$compiler = new JqhtmlWebpackCompiler();
        }

        // Process each JQHTML file
        foreach ($bundle_files as $path) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);

            // Only process JQHTML files
            if ($ext !== 'jqhtml') {
                continue;  // Skip non-JQHTML files
            }

            console_debug('JQHTML', "Processing file: {$path}");

            // Generate temp file path for compiled output. Keyed on the checkout-
            // RELATIVE path plus file CONTENT (not absolute path + mtime/size): the
            // temp filename participates in the bundle's file ordering, so a checkout-
            // or mtime-dependent name would make the compiled-template concatenation
            // order differ between two byte-identical checkouts.
            $cache_key = md5(_rsx_relative_build_path($path) . ':' . md5_file($path));
            $temp_file = storage_path('rsx-tmp/jqhtml_' . substr($cache_key, 0, 16) . '.js');

            // Check if we need to compile
            $needs_compile = !file_exists($temp_file) ||
                           (filemtime($path) > filemtime($temp_file));

            if ($needs_compile) {
                console_debug('JQHTML', "Compiling: {$path}");

                try {
                    // Compile the template using webpack compiler
                    $js_code = static::$compiler->compile_file($path);

                    // Strip the compiler's comment line if present (single-line // comment)
                    if (preg_match('/^\/\/[^\n]*\n(.*)$/s', $js_code, $matches)) {
                        $js_code = $matches[1];
                    }

                    // Add our comment inline without any newlines to preserve sourcemap line offsets
                    $relative_path = str_replace(base_path() . '/', '', $path);
                    $wrapped_code = "/* Compiled from: {$relative_path} */ {$js_code}";

                    // Ensure proper newline at end
                    if (!str_ends_with($wrapped_code, "\n")) {
                        $wrapped_code .= "\n";
                    }

                    // Write to temp file
                    file_put_contents_safe($temp_file, $wrapped_code);

                    console_debug('JQHTML', "Compiled {$path} -> {$temp_file} (" . strlen($wrapped_code) . ' bytes)');
                } catch (Jqhtml_Exception_ViewException $e) {
                    // Let JQHTML ViewExceptions pass through for proper Ignition display
                    throw $e;
                } catch (\Illuminate\View\ViewException $e) {
                    // Let ViewExceptions pass through for proper display
                    throw $e;
                } catch (Exception $e) {
                    // FAIL LOUD - re-throw other exceptions
                    throw new RuntimeException(
                        "Failed to process JQHTML template {$path}: " . $e->getMessage()
                    );
                }
            } else {
                console_debug('JQHTML', "Using cached: {$temp_file}");
            }

            // ALWAYS append the compiled JS file to the bundle (whether freshly compiled or cached)
            $bundle_files[] = $temp_file;
        }

        console_debug('JQHTML', 'Final bundle_files count: ' . count($bundle_files));
    }

    /**
     * Post-processing hook - no longer generates manifest
     * Templates self-register via their compiled code
     */
    public static function after_processing(array $processed_files, array $options = []): array
    {
        // Templates now self-register when their compiled JS executes
        // No need for separate manifest generation
        return [];
    }

    /**
     * Pre-processing hook - reset compiled templates cache
     */
    public static function before_processing(array $all_files, array $options = []): void
    {
    }

    /**
     * Get processor priority (processes before JS)
     */
    public static function get_priority(): int
    {
        return 400;  // Process before JavaScript files
    }

    /**
     * Validate processor configuration
     */
    public static function validate(): void
    {
        // JqhtmlWebpackCompiler must exist - it's a required part of the jqhtml integration

        // Check if @jqhtml/parser is installed
        $package_path = base_path('node_modules/@jqhtml/parser/package.json');
        if (!file_exists($package_path)) {
            throw new RuntimeException(
                "@jqhtml/parser NPM package not found. Run 'npm install' to install @jqhtml packages."
            );
        }
    }

    /**
     * Check if processor should run in current environment
     *
     * @return bool True if processor should run
     */
    public static function is_enabled(): bool
    {
        // Always enabled - bundles control inclusion via module dependencies
        return true;
    }
}
