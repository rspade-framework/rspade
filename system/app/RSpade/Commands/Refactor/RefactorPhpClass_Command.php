<?php

namespace App\RSpade\Commands\Refactor;

use Illuminate\Console\Command;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Commands\Refactor\Php\ClassReferenceScanner;
use App\RSpade\Commands\Refactor\Php\FileUpdater;
use App\RSpade\Core\PHP\Filename_Suggester;

class RefactorPhpClass_Command extends Command
{
    protected $signature = 'rsx:refactor:rename_php_class
        {old_class : The current class name to rename}
        {new_class : The new class name}
        {--dry-run : Show what would be changed without modifying files}
        {--skip-rename-file : Skip renaming the source file after class refactor}';

    protected $description = 'Refactor a PHP class name across all RSX files (PHP and Blade)';

    public function handle()
    {
        $old_class = $this->argument('old_class');
        $new_class = $this->argument('new_class');
        $dry_run = $this->option('dry-run');

        // Initialize manifest
        Manifest::init();

        // Step 1: Validate source class exists in manifest
        $this->info("Validating source class: {$old_class}");

        try {
            $source_path = Manifest::php_find_class($old_class);
            $source_metadata = Manifest::get_file($source_path);
        } catch (\RuntimeException $e) {
            $this->error("Source class '{$old_class}' not found in manifest");
            return 1;
        }

        $source_fqcn = $source_metadata['namespace'] . '\\' . $source_metadata['class'];

        // Step 2: Validate source class is in allowed directories
        if (!str_starts_with($source_path, 'rsx/') && !str_starts_with($source_path, 'app/RSpade/')) {
            $this->error("Source class must be in ./rsx or ./app/RSpade directories");
            $this->error("Found in: {$source_path}");
            return 1;
        }

        $this->info("Source class found: {$source_path}");

        // Step 3: Validate destination class does NOT exist in manifest
        $this->info("Validating destination class: {$new_class}");

        try {
            $conflicting_path = Manifest::php_find_class($new_class);
            $this->error("Destination class '{$new_class}' already exists in manifest");
            $this->error("Conflicting file: {$conflicting_path}");
            return 1;
        } catch (\RuntimeException $e) {
            // Good - destination class doesn't exist
            $this->info("Destination class available");
        }

        // Step 4: Scan all PHP and Blade files for references
        $this->info("");
        $this->info("Scanning for references to '{$old_class}'...");

        $scanner = new ClassReferenceScanner();
        $references = $scanner->find_all_references($old_class, $source_fqcn);

        if (empty($references)) {
            $this->warn("No references found to '{$old_class}'");
            return 0;
        }

        // Step 5: Check for framework file modifications when not in framework developer mode
        if (!config('rsx.code_quality.is_framework_developer', false)) {
            $framework_files = [];
            foreach ($references as $file_path => $occurrences) {
                if (str_starts_with($file_path, 'app/RSpade/')) {
                    $framework_files[] = $file_path;
                }
            }

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
                return 1;
            }
        }

        // Step 6: Display changes preview
        $this->info("");
        $this->info("Found " . count($references) . " file(s) with references:");
        $this->info("");

        foreach ($references as $file_path => $occurrences) {
            $this->line("  <fg=cyan>{$file_path}</>");
            $this->line("    <fg=gray>" . count($occurrences) . " occurrence(s)</>");
        }

        if ($dry_run) {
            $this->info("");
            $this->info("<fg=yellow>DRY RUN - No files modified</>");
            return 0;
        }

        // Step 6.5: Check if this is a controller (subclass of Rsx_Controller)
        $is_controller = false;
        try {
            if (Manifest::php_is_subclass_of($old_class, 'Rsx_Controller_Abstract')) {
                $is_controller = true;
                $this->info("");
                $this->info("<fg=cyan>Detected controller class - will also update Route() references</>");
            }
        } catch (\Exception $e) {
            // Class might not be loaded, skip controller check
        }

        // Step 7: Apply changes
        $this->info("");
        $this->info("Applying changes...");

        $updater = new FileUpdater();
        $updated_count = $updater->update_class_references($references, $old_class, $new_class, $source_fqcn);

        $this->info("");
        $this->info("<fg=green>Successfully updated {$updated_count} file(s)</>");

        // Step 7.5: If controller, do additional Route() replacements
        if ($is_controller) {
            $this->info("");
            $this->info("Updating Route() references in rsx/ files...");

            $route_updated_count = $updater->update_controller_route_references($old_class, $new_class);

            if ($route_updated_count > 0) {
                $this->info("<fg=green>[OK]</> Updated Route() references in {$route_updated_count} file(s)");
            } else {
                $this->line("<fg=gray>No Route() references found</>");
            }
        }

        // Step 8: Auto-rename the source file if needed
        if (!$this->option('skip-rename-file')) {
            $this->info("");
            $this->info("Checking if source file needs renaming...");
            $this->rename_source_file_if_needed($source_path, $new_class, $source_metadata);
        }

        // Step 9: Final grep for any remaining references
        $this->info("");
        $this->info("Checking for remaining references to '{$old_class}'...");

        $remaining_files = $this->grep_remaining_references($old_class);

        if (!empty($remaining_files)) {
            $this->warn("");
            $this->warn("Found {old_class} in " . count($remaining_files) . " file(s) (manual review needed):");
            foreach ($remaining_files as $file) {
                $this->warn("  - {$file}");
            }
        } else {
            $this->info("<fg=green>[OK]</> No remaining references found");
        }

        $this->info("");
        $this->info("Next steps:");
        $this->info("  1. Review changes: git diff");
        $this->info("  2. Test application");

        return 0;
    }

    /**
     * Rename the source file to match the new class name if needed
     *
     * @param string $source_path Current relative file path
     * @param string $new_class New class name
     * @param array $metadata File metadata from manifest
     */
    protected function rename_source_file_if_needed(string $source_path, string $new_class, array $metadata): void
    {
        $extension = $metadata['extension'] ?? 'php';
        $is_rspade = str_starts_with($source_path, 'app/RSpade/');

        // Get suggested filename
        $suggested_filename = Filename_Suggester::get_suggested_class_filename(
            $source_path,
            $new_class,
            $extension,
            $is_rspade,
            false  // Not a Jqhtml component
        );

        $current_filename = basename($source_path);

        // Check if rename is needed
        if ($current_filename === $suggested_filename) {
            $this->info("<fg=green>[OK]</> Source filename already correct: {$current_filename}");
            return;
        }

        // Build new path
        $dir_path = dirname($source_path);
        $new_path = $dir_path . '/' . $suggested_filename;

        // Check if destination already exists
        if (file_exists(base_path($new_path))) {
            $this->warn("<fg=yellow>[WARNING]</> Auto-rename skipped: Destination file already exists: {$new_path}");
            return;
        }

        // Perform rename
        $old_absolute = base_path($source_path);
        $new_absolute = base_path($new_path);

        rename($old_absolute, $new_absolute);
        $this->info("<fg=green>[OK]</> Renamed: {$current_filename} -> {$suggested_filename}");
    }

    /**
     * Grep for remaining references to the old class name in ./rsx
     *
     * @param string $old_class Old class name to search for
     * @return array List of relative file paths where the class name still appears
     */
    protected function grep_remaining_references(string $old_class): array
    {
        $rsx_path = base_path('rsx');

        if (!is_dir($rsx_path)) {
            return [];
        }

        // Use grep to find files containing the old class name
        // -r = recursive
        // -l = list files only
        // --include = only search these file types
        // -w = match whole word
        $command = sprintf(
            'grep -r -l -w %s --include="*.php" --include="*.blade.php" --include="*.js" --include="*.jqhtml" %s 2>/dev/null',
            escapeshellarg($old_class),
            escapeshellarg($rsx_path)
        );

        $output = shell_exec('bash -c ' . escapeshellarg($command));

        if (empty($output)) {
            return [];
        }

        $files = explode("\n", trim($output));

        // Convert to relative paths
        $relative_files = [];
        foreach ($files as $file) {
            if (empty($file)) {
                continue;
            }
            $relative_files[] = str_replace(base_path() . '/', '', $file);
        }

        return $relative_files;
    }
}
