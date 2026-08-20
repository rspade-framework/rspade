<?php

namespace App\RSpade\Commands\Refactor;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Commands\Refactor\Php\MethodReferenceScanner;
use App\RSpade\Commands\Refactor\Php\MethodUpdater;
use RuntimeException;

class RenamePhpClassFunction_Command extends Command
{
    protected $signature = 'rsx:refactor:rename_php_class_function
        {class_name : The class containing the method}
        {old_method : Current static method name}
        {new_method : New static method name}
        {--force-rename-static-self : Only rename self::/static:: references in class}
        {--skip-subclasses : Don\'t recursively rename in subclasses}
        {--dry-run : Show what would be changed without modifying files}';

    protected $description = 'Rename a static method across all RSX files including subclasses';

    public function handle()
    {
        $class_name = $this->argument('class_name');
        $old_method = $this->argument('old_method');
        $new_method = $this->argument('new_method');
        $force_rename_static_self = $this->option('force-rename-static-self');
        $skip_subclasses = $this->option('skip-subclasses');
        $dry_run = $this->option('dry-run');

        // Initialize manifest
        Manifest::init();

        // Phase 1: Validation
        $this->info("Validating method: {$class_name}::{$old_method}");

        try {
            $class_path = Manifest::php_find_class($class_name);
            $class_metadata = Manifest::get_file($class_path);
        } catch (RuntimeException $e) {
            $this->error("Class '{$class_name}' not found in manifest");
            return 1;
        }

        $absolute_class_path = base_path($class_path);

        // Build FQCN from metadata
        $class_fqcn = $class_metadata['namespace'] . '\\' . $class_metadata['class'];

        // Check if method exists and is static
        $method_info = $this->find_method_in_class($absolute_class_path, $old_method);

        if ($method_info === null) {
            if ($force_rename_static_self) {
                $this->line("<fg=yellow>[WARNING]</> Method '{$old_method}' not found in class (allowed with --force-rename-static-self)");
                $method_was_renamed = false;
            } else {
                $this->error("Method '{$class_name}::{$old_method}' not found in class definition");
                return 1;
            }
        } else {
            if (!$method_info['is_static']) {
                $this->error("Method '{$class_name}::{$old_method}' is not static. Only static methods can be renamed.");
                return 1;
            }

            $this->info("<fg=green>[OK]</> Method found and is static");

            // Check if destination method already exists
            $new_method_info = $this->find_method_in_class($absolute_class_path, $new_method);
            if ($new_method_info !== null) {
                $this->error("Method '{$class_name}::{$new_method}' already exists. Cannot rename.");
                return 1;
            }

            $this->info("<fg=green>[OK]</> Destination method available");
            $method_was_renamed = true;
        }

        // Phase 1.5: Pre-flight check for framework file modifications
        $this->preflight_check_framework_files($class_name, $class_fqcn, $old_method, $absolute_class_path, $method_was_renamed, $skip_subclasses);

        if ($dry_run) {
            $this->info("");
            $this->info("<fg=yellow>DRY RUN - No files will be modified</>");
            return $this->preview_changes($class_name, $class_fqcn, $old_method, $new_method, $absolute_class_path, $method_was_renamed, $skip_subclasses);
        }

        // Phase 2: Rename method definition (if it exists)
        if ($method_was_renamed) {
            $this->info("");
            $this->info("Renaming method definition...");

            $updater = new MethodUpdater();
            $renamed = $updater->rename_method_definition($absolute_class_path, $old_method, $new_method);

            if ($renamed) {
                $this->info("<fg=green>[OK]</> Updated: " . $class_path);
            } else {
                $this->error("Failed to rename method definition");
                return 1;
            }
        }

        // Phase 3a: ALWAYS update static::/self:: references in class file
        $this->info("");
        $this->info("Updating static::/self:: references in class...");

        $updater = $updater ?? new MethodUpdater();
        $static_self_count = $updater->update_static_self_references($absolute_class_path, $old_method, $new_method);

        if ($static_self_count > 0) {
            $this->info("<fg=green>[OK]</> Updated {$static_self_count} static::/self:: reference(s) in class");
        } else {
            $this->line("<fg=gray>No static::/self:: references found in class</>");
        }

        // Phase 3b: Update Class::method references in all files (only if method was renamed)
        if ($method_was_renamed) {
            $this->info("");
            $this->info("Scanning for references to '{$class_name}::{$old_method}'...");

            $scanner = new MethodReferenceScanner();
            $references = $scanner->find_all_method_references($class_name, $class_fqcn, $old_method);

            if (!empty($references)) {
                $this->info("Found " . count($references) . " file(s) with references:");
                $this->info("");

                foreach ($references as $file_path => $occurrences) {
                    $relative_path = str_replace(base_path() . '/', '', $file_path);
                    $this->line("  <fg=cyan>{$relative_path}</>");
                    $this->line("    <fg=gray>" . count($occurrences) . " occurrence(s)</>");
                }

                $this->info("");
                $this->info("Updating references...");

                $updated_count = $updater->update_method_references($references, $class_name, $class_fqcn, $old_method, $new_method);
                $this->info("<fg=green>[OK]</> Updated {$updated_count} file(s)");
            } else {
                $this->line("<fg=gray>No external references found</>");
            }
        }

        // Phase 3.5: If controller, update Route() references
        if ($method_was_renamed) {
            $is_controller = false;
            try {
                if (Manifest::php_is_subclass_of($class_name, 'Rsx_Controller_Abstract')) {
                    $is_controller = true;
                }
            } catch (\Exception $e) {
                // Class might not be loaded, skip controller check
            }

            if ($is_controller) {
                $this->info("");
                $this->info("Detected controller class - updating Route() action references...");

                $updater = $updater ?? new MethodUpdater();
                $route_updated_count = $updater->update_controller_action_route_references($class_name, $old_method, $new_method);

                if ($route_updated_count > 0) {
                    $this->info("<fg=green>[OK]</> Updated Route() references in {$route_updated_count} file(s)");
                } else {
                    $this->line("<fg=gray>No Route() action references found</>");
                }
            }
        }

        // Phase 4: Recursive subclass renaming (unless --skip-subclasses)
        if (!$skip_subclasses) {
            $this->info("");
            $this->info("Processing subclasses...");

            $subclasses = Manifest::php_get_subclasses_of($class_name, true);

            if (!empty($subclasses)) {
                $this->info("Found " . count($subclasses) . " subclass(es): " . implode(', ', $subclasses));

                foreach ($subclasses as $subclass) {
                    $exit_code = Artisan::call('rsx:refactor:rename_php_class_function', [
                        'class_name' => $subclass,
                        'old_method' => $old_method,
                        'new_method' => $new_method,
                        '--force-rename-static-self' => true,
                        '--skip-subclasses' => true,
                    ]);

                    if ($exit_code === 0) {
                        $this->line("  <fg=green>[OK]</> Updated {$subclass}");
                    } else {
                        $this->line("  <fg=yellow>[WARNING]</> Skipped {$subclass} (no references)");
                    }
                }
            } else {
                $this->line("<fg=gray>No subclasses found</>");
            }
        }

        // Phase 5: Final grep for any remaining references
        $this->info("");
        $this->info("Checking for remaining references to '{$class_name}' and '{$old_method}' on same line...");

        $remaining_files = $this->grep_remaining_method_references($class_name, $old_method);

        if (!empty($remaining_files)) {
            $this->warn("");
            $this->warn("Found {$class_name} and {$old_method} on same line in " . count($remaining_files) . " file(s) (manual review needed):");
            foreach ($remaining_files as $file) {
                $this->warn("  - {$file}");
            }
        } else {
            $this->info("<fg=green>[OK]</> No remaining references found");
        }

        // Success summary
        $this->info("");
        $this->info("<fg=green>[OK]</> Successfully renamed {$class_name}::{$old_method} -> {$class_name}::{$new_method}");
        $this->info("");
        $this->info("Next steps:");
        $this->info("  1. Review changes: git diff");
        $this->info("  2. Test application");

        return 0;
    }

    /**
     * Grep for files where both class name and old method name appear on the same line
     *
     * @param string $class_name Class name to search for
     * @param string $old_method Old method name to search for
     * @return array List of relative file paths where both appear on same line
     */
    protected function grep_remaining_method_references(string $class_name, string $old_method): array
    {
        $rsx_path = base_path('rsx');

        if (!is_dir($rsx_path)) {
            return [];
        }

        // Use grep to find files containing both class name and method name on same line
        // We'll grep for the class name, then check each result for method name on same line
        $command = sprintf(
            'grep -r -l -w %s --include="*.php" --include="*.blade.php" --include="*.js" --include="*.jqhtml" %s 2>/dev/null',
            escapeshellarg($class_name),
            escapeshellarg($rsx_path)
        );

        $output = shell_exec('bash -c ' . escapeshellarg($command));

        if (empty($output)) {
            return [];
        }

        $candidate_files = explode("\n", trim($output));
        $matching_files = [];

        // For each file that contains the class name, check if any line has both class and method
        foreach ($candidate_files as $file) {
            if (empty($file)) {
                continue;
            }

            // Read file and check each line
            $content = file_get_contents($file);
            $lines = explode("\n", $content);

            foreach ($lines as $line) {
                // Check if line contains both class name and method name as whole words
                if (preg_match('/\b' . preg_quote($class_name, '/') . '\b/', $line) &&
                    preg_match('/\b' . preg_quote($old_method, '/') . '\b/', $line)) {
                    $matching_files[] = str_replace(base_path() . '/', '', $file);
                    break; // Found in this file, no need to check more lines
                }
            }
        }

        return array_unique($matching_files);
    }

    /**
     * Find a method in a class file and return metadata
     *
     * @return array|null ['is_static' => bool, 'line' => int] or null if not found
     */
    protected function find_method_in_class(string $file_path, string $method_name): ?array
    {
        $content = file_get_contents($file_path);
        $tokens = token_get_all($content);

        for ($i = 0; $i < count($tokens); $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === T_FUNCTION) {
                // Find method name
                $method_found = false;
                for ($j = $i + 1; $j < min(count($tokens), $i + 10); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $method_name) {
                        $method_found = true;
                        break;
                    }
                }

                if ($method_found) {
                    // Check if static by looking backwards
                    $is_static = false;
                    for ($k = $i - 1; $k >= max(0, $i - 20); $k--) {
                        if (!is_array($tokens[$k])) continue;
                        if ($tokens[$k][0] === T_STATIC) {
                            $is_static = true;
                            break;
                        }
                    }

                    return [
                        'is_static' => $is_static,
                        'line' => $tokens[$i][2]
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Pre-flight check: Ensure no framework files would be modified when not in framework developer mode
     */
    protected function preflight_check_framework_files(string $class_name, string $class_fqcn, string $old_method, string $class_path, bool $method_was_renamed, bool $skip_subclasses): void
    {
        // Skip check if in framework developer mode
        if (config('rsx.code_quality.is_framework_developer', false)) {
            return;
        }

        $framework_files = [];

        // Check if class file itself is a framework file
        $relative_class_path = str_replace(base_path() . '/', '', $class_path);
        if (str_starts_with($relative_class_path, 'app/RSpade/')) {
            $framework_files[] = $relative_class_path;
        }

        // Check for method references in other files (if method exists)
        if ($method_was_renamed) {
            $scanner = new MethodReferenceScanner();
            $references = $scanner->find_all_method_references($class_name, $class_fqcn, $old_method);

            foreach ($references as $file_path => $occurrences) {
                $relative_path = str_replace(base_path() . '/', '', $file_path);
                if (str_starts_with($relative_path, 'app/RSpade/')) {
                    $framework_files[] = $relative_path;
                }
            }
        }

        // Check subclasses (if not skipped)
        if (!$skip_subclasses) {
            $subclasses = Manifest::php_get_subclasses_of($class_name, true);
            foreach ($subclasses as $subclass) {
                try {
                    $subclass_path = Manifest::php_find_class($subclass);
                    if (str_starts_with($subclass_path, 'app/RSpade/')) {
                        $framework_files[] = $subclass_path;
                    }
                } catch (RuntimeException $e) {
                    // Subclass not found in manifest - skip
                }
            }
        }

        // Deduplicate
        $framework_files = array_unique($framework_files);

        // Throw fatal error if framework files would be modified
        if (!empty($framework_files)) {
            $this->error("");
            $this->error("FATAL: This refactor would modify " . count($framework_files) . " framework file(s) in app/RSpade/");
            $this->error("");
            $this->error("Framework files that would be modified:");
            foreach ($framework_files as $file) {
                $this->error("  - {$file}");
            }
            $this->error("");
            $this->error("Refactoring core framework files is only available to RSpade framework maintainers.");
            $this->error("Set IS_FRAMEWORK_DEVELOPER=true in .env if you are maintaining the framework.");
            exit(1);
        }
    }

    /**
     * Preview changes without modifying files
     */
    protected function preview_changes(string $class_name, string $class_fqcn, string $old_method, string $new_method, string $class_path, bool $method_was_renamed, bool $skip_subclasses): int
    {
        $this->info("");

        if ($method_was_renamed) {
            $this->line("Would rename method definition in:");
            $this->line("  " . str_replace(base_path() . '/', '', $class_path));
            $this->info("");

            $scanner = new MethodReferenceScanner();
            $references = $scanner->find_all_method_references($class_name, $class_fqcn, $old_method);

            if (!empty($references)) {
                $this->line("Would update " . count($references) . " file(s) with {$class_name}::{$old_method} references");
            }
        }

        $static_self_refs = (new MethodReferenceScanner())->find_static_self_references($class_path, $old_method);
        if (!empty($static_self_refs)) {
            $this->line("Would update " . count($static_self_refs) . " static::/self:: reference(s) in class");
        }

        if (!$skip_subclasses) {
            $subclasses = Manifest::php_get_subclasses_of($class_name, true);
            if (!empty($subclasses)) {
                $this->info("");
                $this->line("Would recursively update " . count($subclasses) . " subclass(es):");
                foreach ($subclasses as $subclass) {
                    $this->line("  - {$subclass}");
                }
            }
        }

        return 0;
    }
}
