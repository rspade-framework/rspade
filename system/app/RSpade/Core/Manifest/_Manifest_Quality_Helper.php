<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;

/**
 * _Manifest_Quality_Helper - Code quality and IDE support
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Quality_Helper
{
    /**
    * Process code quality metadata extraction for changed files
    * This runs during Phase 3.5, after PHP classes are loaded but before reflection
    */
    public static function _process_code_quality_metadata(array $changed_files): void
    {
        // Only run in development mode
        if (env('APP_ENV') === 'production') {
            return;
        }

        // Discover rules with on_manifest_file_update method
        $rules_with_update = [];
        $rule_files = glob(base_path('app/RSpade/CodeQuality/Rules/**/*Rule.php'));

        foreach ($rule_files as $rule_file) {
            $rule_class = null;
            $content = file_get_contents($rule_file);

            // Extract class name from file
            if (preg_match('/class\s+(\w+)\s+extends/', $content, $matches)) {
                $class_name = $matches[1];

                // Extract namespace
                if (preg_match('/namespace\s+([^;]+);/', $content, $ns_matches)) {
                    $namespace = $ns_matches[1];
                    $rule_class = $namespace . '\\' . $class_name;
                }
            }

            if ($rule_class && class_exists($rule_class)) {
                // Check if it has the on_manifest_file_update method
                if (method_exists($rule_class, 'on_manifest_file_update')) {
                    $rule_instance = new $rule_class(new \App\RSpade\CodeQuality\Support\ViolationCollector());

                    // Check if it's a manifest-time rule
                    if ($rule_instance->is_called_during_manifest_scan()) {
                        $rules_with_update[] = $rule_instance;
                    }
                }
            }
        }

        // Process each changed file with each rule
        foreach ($changed_files as $file) {
            $absolute_path = base_path($file);

            // Skip if file doesn't exist
            if (!file_exists($absolute_path)) {
                continue;
            }

            // Initialize code_quality_metadata if not present
            if (!isset(Manifest::$data['data']['files'][$file]['code_quality_metadata'])) {
                Manifest::$data['data']['files'][$file]['code_quality_metadata'] = [];
            }

            // Process with each rule that has on_manifest_file_update
            foreach ($rules_with_update as $rule) {
                try {
                    $metadata = $rule->on_manifest_file_update(
                        $absolute_path,
                        file_get_contents($absolute_path),
                        Manifest::$data['data']['files'][$file] ?? []
                    );

                    // Store metadata if returned
                    if ($metadata !== null) {
                        $rule_id = $rule->get_id();
                        Manifest::$data['data']['files'][$file]['code_quality_metadata'][$rule_id] = $metadata;
                    }
                } catch (Throwable $e) {
                    // If the rule throws during metadata extraction, it's a violation
                    // Mark manifest as bad and throw the error
                    Manifest::_set_manifest_is_bad();

                    throw $e;
                }
            }
        }
    }

    /**
    * Run code quality checks during manifest scan
    * Only runs in development mode after manifest changes
    * Throws fatal exception on first violation found
    *
    * Supports two types of rules:
    * - Incremental rules (is_incremental() = true): Only check changed files
    * - Cross-file rules (is_incremental() = false): Run once with full manifest context
    *
    * @param array $changed_files Files that changed in this manifest scan
    */
    public static function _run_manifest_time_code_quality_checks(array $changed_files = []): void
    {
        // Create a minimal violation collector
        $collector = new \App\RSpade\CodeQuality\Support\ViolationCollector();

        // Discover rules that should run during manifest scan
        // This uses direct file scanning, not the manifest, to avoid pollution
        $rules = \App\RSpade\CodeQuality\Support\RuleDiscovery::discover_rules(
            $collector,
            [],
            true // Only get rules with is_called_during_manifest_scan() = true
        );

        // Separate rules by type
        $incremental_rules = [];
        $cross_file_rules = [];

        foreach ($rules as $rule) {
            if ($rule->is_incremental()) {
                $incremental_rules[] = $rule;
            } else {
                $cross_file_rules[] = $rule;
            }
        }

        // Helper to throw violation exception
        $throw_violation = function ($collector) {
            $violations = $collector->get_all();
            if (!empty($violations)) {
                $first_violation = $violations[0];
                $data = $first_violation->to_array();

                Manifest::_set_manifest_is_bad();

                $relative_file = str_replace(base_path() . '/', '', $data['file']);

                $error_message = "Code Quality Violation ({$data['type']}) - {$data['message']}\n\n";
                $error_message .= "File: {$relative_file}:{$data['line']}\n\n";

                if (!empty($data['code'])) {
                    $error_message .= "Code:\n    " . $data['code'] . "\n\n";
                }

                if (!empty($data['resolution'])) {
                    $error_message .= "Resolution:\n" . $data['resolution'];
                }

                throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                    $error_message,
                    0,
                    null,
                    $data['file'],
                    $data['line']
                );
            }
        };

        // Helper to check if file matches rule patterns
        $file_matches_rule = function ($file_path, $rule) {
            $patterns = $rule->get_file_patterns();
            foreach ($patterns as $pattern) {
                $pattern = str_replace('*', '.*', $pattern);
                if (preg_match('/^' . $pattern . '$/i', basename($file_path))) {
                    return true;
                }
            }
            return false;
        };

        // =========================================================
        // INCREMENTAL RULES: Only check changed files
        // =========================================================
        // Determine which files to check - changed files only for incremental rebuild,
        // or all files if this is a full rebuild (empty changed_files means check all)
        $files_to_check = !empty($changed_files)
            ? array_flip($changed_files)  // Use as lookup for O(1) checks
            : null;  // null = check all files

        foreach ($incremental_rules as $rule) {
            foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
                // Skip files that haven't changed (for incremental rebuilds)
                if ($files_to_check !== null && !isset($files_to_check[$file_path])) {
                    continue;
                }

                if (!$file_matches_rule($file_path, $rule)) {
                    continue;
                }

                $absolute_path = base_path($file_path);
                if (!file_exists($absolute_path)) {
                    continue;
                }

                $contents = file_get_contents($absolute_path);

                $enhanced_metadata = $metadata;
                if (isset(Manifest::$data['data']['files'][$file_path]['code_quality_metadata'][$rule->get_id()])) {
                    $enhanced_metadata['rule_metadata'] = Manifest::$data['data']['files'][$file_path]['code_quality_metadata'][$rule->get_id()];
                }

                $rule->check($absolute_path, $contents, $enhanced_metadata);
                $throw_violation($collector);
            }
        }

        // =========================================================
        // CROSS-FILE RULES: Run once with full manifest context
        // =========================================================
        // These rules internally access Manifest::get_all() to check all files
        // We just need to trigger them once with any matching file
        foreach ($cross_file_rules as $rule) {
            // Find first file matching the rule's patterns to trigger the check
            foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
                if (!$file_matches_rule($file_path, $rule)) {
                    continue;
                }

                $absolute_path = base_path($file_path);
                if (!file_exists($absolute_path)) {
                    continue;
                }

                $contents = file_get_contents($absolute_path);

                $enhanced_metadata = $metadata;
                if (isset(Manifest::$data['data']['files'][$file_path]['code_quality_metadata'][$rule->get_id()])) {
                    $enhanced_metadata['rule_metadata'] = Manifest::$data['data']['files'][$file_path]['code_quality_metadata'][$rule->get_id()];
                }

                // Cross-file rules run once, they handle iteration internally
                $rule->check($absolute_path, $contents, $enhanced_metadata);
                $throw_violation($collector);

                // Only trigger once per cross-file rule
                break;
            }
        }
    }

    /**
    * Check for duplicate base class names within the same file type
    *
    * This method handles two scenarios:
    * 1. RESTORE: If a .upstream file exists but no active override exists, restore it
    * 2. OVERRIDE: When a class exists in both rsx/ and app/RSpade/, rename framework to .upstream
    *
    * Throws a fatal error if duplicates exist within the same area (both rsx/ or both app/RSpade/)
    */
    public static function _check_unique_base_class_names(): void
    {
        // ==================================================================================
        // STEP 1: Restore orphaned .upstream files
        // ==================================================================================
        // Check all php.upstream files - if their class no longer has an override in rsx/,
        // restore the framework file by renaming .upstream back to .php
        // ==================================================================================
        Manifest::_restore_orphaned_upstream_files();

        // ==================================================================================
        // STEP 2: Group classes by extension, then by class name
        // ==================================================================================
        $classes_by_extension = [];

        foreach (Manifest::$data['data']['files'] as $file => $metadata) {
            if (isset($metadata['class']) && !empty($metadata['class'])) {
                $base_class_name = $metadata['class'];
                $extension = $metadata['extension'] ?? '';

                // Skip php.upstream files - they're tracked separately for restoration
                if ($extension === 'php.upstream') {
                    continue;
                }

                // Group JavaScript-like files together
                if (in_array($extension, ['js', 'jsx', 'ts', 'tsx'])) {
                    $extension = 'js';
                }

                if (!isset($classes_by_extension[$extension])) {
                    $classes_by_extension[$extension] = [];
                }

                if (!isset($classes_by_extension[$extension][$base_class_name])) {
                    $classes_by_extension[$extension][$base_class_name] = [];
                }

                $classes_by_extension[$extension][$base_class_name][] = $file;
            }
        }

        // ==================================================================================
        // STEP 3: Check for duplicates and create overrides
        // ==================================================================================
        foreach ($classes_by_extension as $extension => $base_class_files) {
            foreach ($base_class_files as $class_name => $files) {
                if (count($files) > 1) {
                    // Check if this is a valid override (rsx/ vs app/RSpade/)
                    $rsx_files = array_filter($files, fn($f) => str_starts_with($f, 'rsx/'));
                    $framework_files = array_filter($files, fn($f) => str_starts_with($f, 'app/RSpade/'));

                    // Valid override: exactly one file in rsx/, rest in app/RSpade/
                    if (count($rsx_files) === 1 && count($framework_files) >= 1) {
                        $rsx_file = array_values($rsx_files)[0];
                        $rsx_metadata = Manifest::$data['data']['files'][$rsx_file] ?? [];
                        $rsx_extends = $rsx_metadata['extends'] ?? null;

                        // Check if rsx/ class extends the framework class (wrong pattern)
                        // This happens when someone tries to use OOP inheritance instead of
                        // the correct RSX override pattern (copy and replace)
                        if ($rsx_extends === $class_name) {
                            $framework_file = array_values($framework_files)[0];
                            throw new \RuntimeException(
                                "Fatal: Invalid class override pattern for '{$class_name}'.\n\n" .
                                "The file {$rsx_file} extends the framework class {$class_name},\n" .
                                "but RSX requires unique class names - you cannot have two classes\n" .
                                "with the same name, even if one extends the other.\n\n" .
                                "CORRECT OVERRIDE PATTERN:\n" .
                                "  1. Copy the framework file to your rsx/ directory:\n" .
                                "     cp system/{$framework_file} {$rsx_file}\n\n" .
                                "  2. Customize the copy as needed (change namespace, add methods, etc.)\n\n" .
                                "  3. The framework will automatically detect this and rename the\n" .
                                "     original to .upstream, allowing your version to take over.\n\n" .
                                "This pattern replaces the framework class entirely, allowing full\n" .
                                "customization while maintaining the same class name for compatibility."
                            );
                        }

                        $did_change = false;
                        // Rename framework files to .upstream and remove from manifest
                        foreach ($framework_files as $framework_file) {
                            $full_framework_path = base_path($framework_file);
                            $upstream_path = $full_framework_path . '.upstream';

                            if (file_exists($full_framework_path) && !file_exists($upstream_path)) {
                                // Normal case: rename to .upstream
                                rename($full_framework_path, $upstream_path);
                                console_debug('MANIFEST', "Class override: {$class_name} - moved {$framework_file} to .upstream");
                                // Marker: .php now absent, .php.upstream now present.
                                \App\RSpade\Core\Framework\Framework_Mutations::record_rename($full_framework_path, $upstream_path, 'class_override_rename');
                                $did_change = true;
                            } elseif (file_exists($full_framework_path) && file_exists($upstream_path)) {
                                // Self-healing: both .php and .php.upstream exist (e.g., after framework update)
                                // Remove the .php file since .upstream is the correct archived version
                                unlink($full_framework_path);
                                console_debug('MANIFEST', "Class override: {$class_name} - removed duplicate {$framework_file} (upstream already exists)");
                                // Marker: .php now absent, the pre-existing .php.upstream stands as present.
                                \App\RSpade\Core\Framework\Framework_Mutations::record_rename($full_framework_path, $upstream_path, 'class_override_selfheal');
                                $did_change = true;
                            }

                            // Remove from manifest data so it won't be indexed
                            unset(Manifest::$data['data']['files'][$framework_file]);
                        }

                        if ($did_change) {
                            Manifest::$_needs_manifest_restart = true;
                        }
                        continue;
                    }

                    // Not a valid override - throw error
                    $file_type = $extension === 'php' ? 'PHP' : ($extension === 'js' ? 'JavaScript' : $extension);

                    throw new \RuntimeException(
                        "Fatal: Duplicate {$file_type} class name '{$class_name}' found in multiple files:\n" .
'  - ' . implode("\n  - ", $files) . "\n" .
'Each class name must be unique within the same file type.'
                    );
                }
            }
        }
    }

    /**
    * Testable seam for the composer autoloader dump. Null = the real runner
    * (_run_composer_dump). Tests swap in a spy to assert the dump was / was not
    * invoked without actually shelling out to composer.
    *
    * @var callable|null
    */
    public static $_composer_dump_runner = null;

    /**
    * Validate composer's committed classmap against the filesystem and regenerate it
    * (blocking `composer dump-autoload`) when it has gone stale.
    *
    * WHY HERE: this runs in the manifest rebuild immediately after the class-override
    * rename/restore pass (_check_unique_base_class_names) has reached its settled state
    * for this rebuild - the exact moment classmap staleness is created (an override was
    * added: framework `.php` -> `.php.upstream`) or cured (an override was removed).
    * Composer's committed classmap still maps the framework FQCN to the renamed `.php`
    * path; left alone, composer's ClassLoader would `include` a missing file on every
    * request (tolerated at runtime by Autoloader's scoped warning carve-out, but the
    * on-disk data is still wrong). Regenerating the classmap here heals the data so
    * future requests/processes never hit the miss at all.
    *
    * The two mechanisms are complementary: the error-handler tolerance is the RUNTIME
    * guarantee (keeps THIS process working, whose in-memory classmap is already loaded
    * and cannot be un-staled mid-request); this dump is the DATA HYGIENE (fixes the
    * file for next time).
    *
    * Cost: ~10-20k warm file_exists() stats (tens of ms). It runs ONLY on code-change
    * rebuilds (this whole pipeline is rebuild-only; a no-change dev request loads cache
    * and never reaches here) and MUST NOT be cache-skipped beyond that gating.
    *
    * DEV-MODE ONLY concern: prod/sealed builds never auto-rebuild, and rsx:prod:build
    * already regenerates the composer autoloader (Prod_Build_Command step 3). The
    * validator simply runs wherever the override pass runs (dev rebuild +
    * framework:pull-triggered rebuild); no special prod handling is needed. It writes
    * only into vendor/composer (via the composer subprocess) and a rsx-tmp temp file
    * (via exec_safe) - never under rsx-build - so the sealed-build write-choke guards
    * are not engaged.
    *
    * @param string|null $classmap_path Absolute path to autoload_classmap.php.
    *                                   Null = the framework's committed classmap.
    */
    public static function _validate_composer_classmap(?string $classmap_path = null): void
    {
        $classmap_path = $classmap_path ?? base_path('vendor/composer/autoload_classmap.php');

        // No classmap present (e.g. a minimal install) - nothing to validate.
        if (!file_exists($classmap_path)) {
            return;
        }

        $stale = static::_find_stale_classmap_entries($classmap_path);
        if (empty($stale)) {
            return;
        }

        console_debug('AUTOLOAD', 'Composer classmap has ' . count($stale) . ' stale entr' . (count($stale) === 1 ? 'y' : 'ies') . ' (points at renamed/removed files) - regenerating with composer dump-autoload');

        $runner = static::$_composer_dump_runner ?? [static::class, '_run_composer_dump'];
        $runner($stale);

        // Record the regenerated composer autoloader outputs as framework-authored
        // mutations. Placed at the call site so both the real runner and the test
        // seam are covered. A no-op composer runner (e.g. composer absent) leaves the
        // outputs unchanged, so re-recording their identical content is harmless.
        \App\RSpade\Core\Framework\Framework_Mutations::record_composer_dump();
    }

    /**
    * Return classmap entries whose mapped file no longer exists on disk (the staleness
    * created by the override pass renaming a framework `.php` to `.php.upstream`).
    * Pure and testable: includes the classmap file and file_exists()-checks every path.
    *
    * @return array<string,string> FQCN => missing absolute path
    */
    public static function _find_stale_classmap_entries(string $classmap_path): array
    {
        // The generated classmap file returns FQCN => absolute path.
        $class_map = include $classmap_path;
        if (!is_array($class_map)) {
            return [];
        }

        $stale = [];
        foreach ($class_map as $fqcn => $path) {
            if (!file_exists($path)) {
                $stale[$fqcn] = $path;
            }
        }

        return $stale;
    }

    /**
    * Default composer-dump runner: blocking `composer dump-autoload` in base_path()
    * (= system/, where the framework composer.json lives). Fails LOUD on a non-zero
    * exit - a broken dump must never be silent. If composer is not on PATH we cannot
    * heal the on-disk classmap here; that is logged (the runtime warning tolerance
    * still keeps things working) and we return rather than fatal every rebuild.
    *
    * @param array<string,string> $stale The stale entries that triggered the dump.
    */
    public static function _run_composer_dump(array $stale): void
    {
        // Mirror Prod_Build_Command::_composer_available(): a missing composer binary
        // is not a hard failure - the Autoloader warning carve-out covers runtime, and
        // failing loud on every rebuild would break dev entirely.
        $composer_path = trim((string) @shell_exec('bash -c ' . escapeshellarg('command -v composer 2>/dev/null')));
        if ($composer_path === '') {
            console_debug('AUTOLOAD', 'composer not found on PATH - cannot regenerate stale classmap (runtime warning tolerance will cover it)');

            return;
        }

        // --no-scripts is LOAD-BEARING, not an optimization. Composer's stock
        // post-autoload-dump scripts include `@php artisan package:discover`, i.e. a
        // brand-new artisan process spawned mid-pull, on a tree that is being swapped
        // underneath it, with the runtime services stopped. It also cannot inherit the
        // pull's --_framework-update-override (an argv token), so it depends entirely on
        // the maintenance gate's classification to run at all. Any failure there exits
        // the dump non-zero and fires the throw below mid-pull. The heal only needs
        // autoload_classmap.php regenerated;
        // package:discover warms Laravel's package manifest, which a class-override
        // rename cannot invalidate and the rebuild re-runs regardless. Skipping the
        // scripts removes the nested-artisan failure class entirely.
        $output = [];
        $return_var = 0;
        \exec_safe('cd ' . escapeshellarg(base_path()) . ' && composer dump-autoload --optimize --no-scripts --no-interaction 2>&1', $output, $return_var);

        if ($return_var !== 0) {
            throw new \RuntimeException(
                "Fatal: composer dump-autoload failed (exit {$return_var}) while regenerating a stale classmap.\n\n" .
                "The manifest override pass renamed framework files to .upstream, leaving " . count($stale) . " stale classmap entr" . (count($stale) === 1 ? 'y' : 'ies') . ".\n" .
                "Composer's regeneration must succeed to heal the on-disk classmap.\n\n" .
                "composer output:\n" . implode("\n", $output)
            );
        }

        console_debug('AUTOLOAD', 'composer classmap regenerated (' . count($stale) . ' stale entr' . (count($stale) === 1 ? 'y' : 'ies') . ' cleared)');
    }

    /**
    * Generate VS Code IDE helper stubs for attributes and class aliases
    */
    public static function _generate_vscode_stubs(): void
    {
        // Generate to project root (parent of system/)
        $project_root = dirname(base_path());
        $stub_file = $project_root . '/._rsx_helper.php';

        $output = "<?php\n";
        $output .= "/* @noinspection ALL */\n";
        $output .= "// @formatter:off\n";
        $output .= "// phpcs:ignoreFile\n\n";
        $output .= "/**\n";
        $output .= " * RSX Framework Attribute Stubs for IDE Support\n";
        $output .= " *\n";
        $output .= " * AUTO-GENERATED FILE - DO NOT EDIT MANUALLY\n";
        $output .= " *\n";
        $output .= " * This file is automatically regenerated during manifest builds when:\n";
        $output .= " * - Running `php artisan rsx:manifest:build`\n";
        $output .= " * - File changes detected in development mode\n";
        $output .= " * - Any PHP file with attributes is modified\n";
        $output .= " *\n";
        $output .= " * These are stub definitions to provide IDE autocomplete and eliminate warnings\n";
        $output .= " * for RSX framework attributes. These classes are never actually loaded or used\n";
        $output .= " * at runtime - they exist purely for IDE IntelliSense.\n";
        $output .= " *\n";
        $output .= " * This file should not be included in your code, only analyzed by your IDE!\n";
        $output .= " */\n\n";

        // Generate attribute stubs
        $attributes = [];

        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
                continue;
            }

            if (!isset($metadata['class'])) {
                continue;
            }

            // Collect attributes from the class itself
            if (isset($metadata['attributes'])) {
                foreach ($metadata['attributes'] as $attr_name => $attr_data) {
                    // Extract simple attribute name (last part after backslash)
                    $simple_attr_name = basename(str_replace('\\', '/', $attr_name));
                    $attributes[$simple_attr_name] = true;
                }
            }

            // Collect attributes from public static methods
            if (isset($metadata['public_static_methods'])) {
                foreach ($metadata['public_static_methods'] as $method_name => $method_info) {
                    if (isset($method_info['attributes'])) {
                        foreach ($method_info['attributes'] as $attr_name => $attr_data) {
                            // Extract simple attribute name (last part after backslash)
                            $simple_attr_name = basename(str_replace('\\', '/', $attr_name));
                            $attributes[$simple_attr_name] = true;
                        }
                    }
                }
            }
        }

        if (!empty($attributes)) {
            $output .= "namespace {\n";
            foreach (array_keys($attributes) as $attr_name) {
                $output .= "    #[\\Attribute(\\Attribute::TARGET_ALL | \\Attribute::IS_REPEATABLE)]\n";
                $output .= "    class {$attr_name} {\n";
                $output .= "        public function __construct(...\$args) {}\n";
                $output .= "    }\n\n";
            }
            $output .= "}\n";
        }

        file_put_contents_safe($stub_file, $output);
    }

}
