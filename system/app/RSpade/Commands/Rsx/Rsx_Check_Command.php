<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\CodeQuality\CodeQualityChecker;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Locks\RsxLocks;
use Symfony\Component\Finder\Finder;

class Rsx_Check_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:check
                            {paths?* : Files or directories to check (defaults to rsx/ and app/)}
                            {--fix : Attempt to automatically fix violations (not implemented yet)}
                            {--json : Output results as JSON}
                            {--silent : Only show output if violations are found}
                            {--pre-commit-tests : Enable pre-commit checks including rsx/temp validation}
                            {--convention : Show convention violations}
                            {--max= : Maximum number of violations to display}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check code for RSpade framework standards compliance';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Place .placeholder files in all empty directories
        $this->place_placeholder_files();

        // Acquire the manifest build SYSTEM lock to prevent concurrent manifest rebuilds.
        // Reentrant: if the manifest rebuild inside this command takes the same lock, the
        // nested acquire is counted here and never reaches the backend.
        $lock_token = RsxLocks::system_lock(RsxLocks::LOCK_MANIFEST_BUILD);

        try {
            // Clean up any existing code quality remediation report
        $remediation_file = base_path('CODE_QUALITY_REMEDIATION.md');
        if (file_exists($remediation_file)) {
            unlink($remediation_file);
        }

        $quiet = $this->option('silent');

        if (!$quiet) {
            $this->info('RSX Code Quality Checker');
            $this->info('==============================');
            $this->newLine();
        }
        
        // Get pre-commit-tests flag
        $pre_commit_tests = $this->option('pre-commit-tests');
        
        // Get paths from argument or use defaults
        $paths = $this->argument('paths');
        $using_default_paths = empty($paths);
        if ($using_default_paths) {
            $paths = ['rsx', 'app'];
        }

        // Tell the checker to exclude manifest-time rules
        // They've already run when the manifest was loaded, so running them again is redundant
        $config['exclude_manifest_time_rules'] = true;

        // Initialize code quality checker with config
        $config = [
            'pre_commit_tests' => $pre_commit_tests,
            'using_default_paths' => $using_default_paths
        ];
        CodeQualityChecker::init($config);
        
        $total_files = 0;
        $controller_files = 0;
        
        // Collect all files to check
        $files_to_check = [];
        
        // Process each path (file or directory)
        foreach ($paths as $path) {
            // Handle absolute or relative paths
            if (str_starts_with($path, '/')) {
                $full_path = $path;
            } else {
                $full_path = base_path($path);
            }
            
            // Check if path exists
            if (!file_exists($full_path)) {
                $this->error("[ERROR] Error: Path does not exist: {$path}");
                return 1;
            }
            
            // Check if it's a file
            if (is_file($full_path)) {
                // Get scan directories from config
                $scan_directories = config('rsx.manifest.scan_directories', []);
                $relative_path = str_replace(base_path() . '/', '', $full_path);
                
                // Check if file is in allowed directories
                $in_valid_path = false;

                // Special case: Allow Console Command files
                if (str_starts_with($relative_path, 'app/Console/Commands/')) {
                    $in_valid_path = true;
                } else {
                    foreach ($scan_directories as $scan_path) {
                        // Skip specific file entries in scan_directories
                        if (str_contains($scan_path, '.')) {
                            // This is a specific file, check exact match
                            if ($relative_path === $scan_path) {
                                $in_valid_path = true;
                                break;
                            }
                        } else {
                            // This is a directory, check if file is within it
                            if (str_starts_with($relative_path, rtrim($scan_path, '/') . '/') ||
                                rtrim($relative_path, '/') === rtrim($scan_path, '/')) {
                                $in_valid_path = true;
                                break;
                            }
                        }
                    }
                }

                if (!$in_valid_path) {
                    // Build list of allowed directories (excluding specific files)
                    $allowed_dirs = [];
                    foreach ($scan_directories as $scan_path) {
                        if (!str_contains($scan_path, '.')) {
                            $allowed_dirs[] = $scan_path;
                        }
                    }
                    
                    $this->error("[ERROR] Error: File '{$path}' is not in an allowed directory");
                    $this->warn("[WARNING]  Allowed directories:");
                    foreach ($allowed_dirs as $dir) {
                        $this->info("   - {$dir}/");
                    }
                    $this->newLine();
                    $this->warn("For testing code quality rules, place your test file in rsx/temp/");
                    $this->info("   Example: mv {$path} " . base_path('rsx/temp/') . basename($path));
                    
                    // Add framework developer message if enabled
                    if (config('rsx.code_quality.is_framework_developer', false)) {
                        $this->newLine();
                        $this->comment("TIP: Framework Developer Mode: If testing a rule that only targets app/RSpade/,");
                        $this->comment("   place the file in app/RSpade/temp/ for testing (create the directory if needed).");
                        $this->info("   Example: mkdir -p " . base_path('app/RSpade/temp') . " && mv {$path} " . base_path('app/RSpade/temp/') . basename($path));
                    }
                    
                    $this->newLine();
                    return 1;
                }
                
                // Check if file is in the manifest (skip for Console Commands)
                if (!str_starts_with($relative_path, 'app/Console/Commands/')) {
                    $manifest = Manifest::get_all();
                    $found_in_manifest = false;
                    foreach ($manifest as $manifest_path => $metadata) {
                        if (rsxrealpath($full_path) === rsxrealpath(base_path($manifest_path))) {
                            $found_in_manifest = true;
                            break;
                        }
                    }

                    if (!$found_in_manifest) {
                        $this->error("[ERROR] Error: File '{$path}' is not in the manifest");
                        $this->warn("[WARNING]  The file must be indexed by the manifest to be checked");
                        $this->info("   Try running: php artisan rsx:manifest:build");
                        $this->newLine();
                        return 1;
                    }
                }
                
                if (!$quiet) {
                    $this->info("Checking file: {$path}");
                }
                $total_files++;
                $files_to_check[] = $full_path;
                
                // Count controller/model files for stats
                if ($this->is_controller_file($full_path) || $this->is_model_file($full_path)) {
                    $controller_files++;
                }
                continue;
            }
            
            // Check if it's a directory
            if (!is_dir($full_path)) {
                $this->error("[ERROR] Error: Path is neither a file nor a directory: {$path}");
                return 1;
            }
            
            // Get scan directories from config for validation
            $scan_directories = config('rsx.manifest.scan_directories', []);
            $relative_dir = str_replace(base_path() . '/', '', $full_path);
            $relative_dir = rtrim($relative_dir, '/');
            
            // Check if directory is in allowed paths
            $in_valid_path = false;
            foreach ($scan_directories as $scan_path) {
                // Skip specific file entries
                if (str_contains($scan_path, '.')) {
                    continue;
                }
                
                $scan_dir = rtrim($scan_path, '/');
                if ($relative_dir === $scan_dir || 
                    str_starts_with($relative_dir, $scan_dir . '/') ||
                    str_starts_with($scan_dir, $relative_dir . '/')) {
                    $in_valid_path = true;
                    break;
                }
            }
            
            // Special handling for 'app' directory to scan app/RSpade subdirectories
            if ($relative_dir === 'app') {
                $in_valid_path = true;
            }
            
            if (!$in_valid_path) {
                // Build list of allowed directories (excluding specific files)
                $allowed_dirs = [];
                foreach ($scan_directories as $scan_path) {
                    if (!str_contains($scan_path, '.')) {
                        $allowed_dirs[] = $scan_path;
                    }
                }
                
                $this->error("[ERROR] Error: Directory '{$path}' is not within allowed scan directories");
                $this->warn("[WARNING]  Allowed directories:");
                foreach ($allowed_dirs as $dir) {
                    $this->info("   - {$dir}/");
                }
                $this->newLine();
                return 1;
            }
            
            if (!$quiet) {
                $this->info("Scanning: {$path}/");
            }
            $is_rsx_dir = ($path === 'rsx');

            // Get all manifest files for this directory
            $manifest = Manifest::get_all();

            foreach ($manifest as $manifest_path => $metadata) {
                // Convert manifest path to full path
                $manifest_full_path = base_path($manifest_path);

                // Check if this manifest file is within the requested directory
                // Either the file is directly in the directory or in a subdirectory
                if (str_starts_with($manifest_path, $relative_dir . '/') || $manifest_path === $relative_dir) {
                    // Only include files that exist
                    if (file_exists($manifest_full_path)) {
                        // Avoid duplicates
                        if (!in_array($manifest_full_path, $files_to_check)) {
                            $total_files++;
                            $files_to_check[] = $manifest_full_path;

                            // Count controller/model files for stats (if in RSX directory)
                            if ($is_rsx_dir) {
                                if ($this->is_controller_file($manifest_full_path) || $this->is_model_file($manifest_full_path)) {
                                    $controller_files++;
                                }
                            }
                        }
                    }
                }
            }
        }

        // If using default paths, also include Console Commands for rules that support them
        if ($using_default_paths) {
            $commands_dir = base_path('app/Console/Commands');
            if (is_dir($commands_dir)) {
                if (!$quiet) {
                    $this->info("Including Console Commands for supporting rules...");
                }

                $command_files = [];
                $command_finder = new Finder();
                $command_finder->files()
                    ->in($commands_dir)
                    ->name('*.php')
                    ->exclude(['vendor', 'node_modules']);

                foreach ($command_finder as $file) {
                    $command_files[] = $file->getPathname();
                }

                // Add Console Command files to the check list
                // The checker will determine which rules support them
                foreach ($command_files as $file) {
                    if (!in_array($file, $files_to_check)) {
                        $files_to_check[] = $file;
                        $total_files++;
                    }
                }
            }
        }

        // Filter out app/RSpade files if not in framework developer mode
        if (!config('rsx.code_quality.is_framework_developer', false)) {
            $files_to_check = array_filter($files_to_check, function($file) {
                $relative_path = str_replace(base_path() . '/', '', $file);
                return !str_starts_with($relative_path, 'app/RSpade/');
            });
            // Re-index array after filtering
            $files_to_check = array_values($files_to_check);
        }

        // Run checks through the new modular system
        CodeQualityChecker::check_files($files_to_check);
        
        // Get violations
        $violations = CodeQualityChecker::get_violations();
        $convention_count = CodeQualityChecker::get_collector()->get_convention_count();
        $show_conventions = $this->option('convention');

        // If showing conventions, get convention violations
        if ($show_conventions) {
            $violations = CodeQualityChecker::get_collector()->get_convention_violations_as_arrays();
        }

        // In quiet mode, only output if there are violations
        if ($quiet && empty($violations) && !$convention_count) {
            return 0;
        }
        
        // If violations exist or not in quiet mode, show output
        if (!empty($violations) || !$quiet) {
            // Always show header and counts when there are violations (even in quiet mode)
            if (!empty($violations) && $quiet) {
                $this->info('RSX Code Quality Checker');
                $this->info('==============================');
                $this->newLine();
            }
            
            if (!$quiet) {
                $this->newLine();
            }
            $this->info("Files scanned: {$total_files}");
            $this->info("Controllers/Models checked: {$controller_files}");
            $this->newLine();
        }
        
        // Show convention warning if not displaying conventions
        if (!$show_conventions && $convention_count > 0) {
            $this->warn("[WARNING]  Warning: There are ({$convention_count}) RSX application convention warnings, call rsx:check --convention to view the warnings");
            $this->newLine();
        }

        if (empty($violations)) {
            if ($show_conventions) {
                $this->info('[OK] No convention violations found!');
            } else {
                $this->info('[OK] No code standard violations found!');
            }
            return 0;
        }
        
        // Apply --max limit if specified
        $total_violations = count($violations);
        $max_violations = $this->option('max');
        $violations_truncated = false;

        if ($max_violations && is_numeric($max_violations) && $max_violations > 0) {
            $max_violations = (int)$max_violations;
            if ($total_violations > $max_violations) {
                $violations = array_slice($violations, 0, $max_violations);
                $violations_truncated = true;
            }
        }

        // Output violations
        if ($this->option('json')) {
            $this->output_json($violations, $total_violations, $violations_truncated);
        } else {
            $this->output_table($violations, $total_violations, $violations_truncated);
        }

        // Summary
        $this->newLine();
        if ($show_conventions) {
            $this->warn('[WARNING]  Found ' . $total_violations . ' convention violation(s)');
        } else {
            $this->error('[ERROR] Found ' . $total_violations . ' code standard violation(s)');
        }

        // Show truncation message if violations were limited
        if ($violations_truncated) {
            $shown = count($violations);
            $remaining = $total_violations - $shown;
            $this->newLine();
            $this->comment("Showing first {$shown} violations. {$remaining} additional violations are present.");
        } elseif ($max_violations && $total_violations > 0 && $total_violations <= $max_violations) {
            $this->newLine();
            $this->comment("All {$total_violations} violations shown (within --max={$max_violations} limit).");
        }

        // Notice about potential validator errors
        $this->newLine();
        $this->comment('ℹ  Notice: While these violations have been detected, please verify each issue carefully.');
        $this->comment('   The validators themselves may contain errors. If a violation appears incorrect,');
        $this->comment('   thoroughly review both the code and the validator logic. It may be necessary');
        $this->comment('   to fix the validator rather than the code.');

        // Convention violations don't cause non-zero exit
        if ($show_conventions) {
            return 0;
        }

        // Always return non-zero exit code when violations are found
        return 1;
        } finally {
            // Release the manifest build lock
            RsxLocks::release_lock($lock_token);
        }
    }
    
    /**
     * Output violations as a table
     */
    protected function output_table($violations, $total_violations = null, $violations_truncated = false)
    {
        $this->warn('Code Standard Violations:');
        $this->newLine();
        
        $current_file = null;
        
        foreach ($violations as $violation) {
            // Debug output to understand violations
            if (!isset($violation['file'])) {
                $this->error("Invalid violation structure: " . json_encode($violation));
                continue;
            }
            
            // Group by file for better readability
            $relative_file = str_replace(base_path() . '/', '', $violation['file']);
            
            if ($current_file !== $relative_file) {
                if ($current_file !== null) {
                    $this->newLine();
                }
                $this->line("<fg=cyan>{$relative_file}</>");
                $current_file = $relative_file;
            }
            
            $line = str_pad($violation['line'], 6, ' ', STR_PAD_LEFT);
            
            // Use simplified output format for all violations
            $type = $violation['type'] ?? 'unknown';
            $message = $violation['message'] ?? 'No message provided';
            $code = $violation['code'] ?? null;
            $resolution = $violation['resolution'] ?? null;
            
            // Show the violation
            $this->line("  <fg=yellow>Line {$line}:</> <fg=cyan>[{$type}]</>");
            $this->line("  <fg=gray>          -></> {$message}");
            
            // Show code snippet if available
            if ($code) {
                $this->line("  <fg=gray>          Code:</> <fg=red>{$code}</>");
            }
            
            // Show resolution if available
            if ($resolution) {
                $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$resolution}</>");
            }
            
            continue; // Skip the old if-elseif chain
            
            // OLD CODE BELOW - TO BE REMOVED
            if (false && $violation['type'] === 'filename_case') {
                $filename = $violation['filename'] ?? 'unknown';
                $suggested = $violation['suggested'] ?? '';
                $this->line("  <fg=yellow>File:</> <fg=red>{$filename}</>");
                $this->line("  <fg=gray>      -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>      -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'defensive_coding') {
                $variable = $violation['variable'] ?? 'unknown';
                $code = $violation['code'] ?? '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'mass_assignment_property') {
                $property = $violation['property'] ?? 'unknown';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>protected \${$property}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'instance_method') {
                $method = $violation['method'] ?? 'unknown';
                $class = $violation['class'] ?? 'unknown';
                $code = $violation['code'] ?? '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'instance_pattern') {
                $class = $violation['class'] ?? 'unknown';
                $code = $violation['code'] ?? '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'this_usage') {
                $class = $violation['class'] ?? 'unknown';
                $code = $violation['code'] ?? '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'this_assignment') {
                $class = $violation['class'] ?? 'unknown';
                $code = $violation['code'] ?? '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'var_keyword') {
                $code = $violation['code'] ?? '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'jquery_usage') {
                $code = $violation['code'] ?? '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'document_ready') {
                $code = $violation['code'] ?? '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'fallback_legacy') {
                $code = $violation['code'] ?? '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'function_exists') {
                $code = $violation['code'] ?? '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'php_syntax_error') {
                $this->line("  <fg=red>[WARNING] PHP Syntax Error</>");
                if ($line > 0) {
                    $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$violation['message']}</>");
                } else {
                    $this->line("  <fg=red>{$violation['message']}</>");
                }
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'js_syntax_error') {
                $this->line("  <fg=red>[WARNING] JavaScript Syntax Error</>");
                if ($line > 0) {
                    $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$violation['message']}</>");
                } else {
                    $this->line("  <fg=red>{$violation['message']}</>");
                }
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'json_syntax_error') {
                $this->line("  <fg=red>[WARNING] JSON Syntax Error</>");
                $this->line("  <fg=red>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'enhanced_filename') {
                // Enhanced filename violations
                $filename = $violation['filename'] ?? 'N/A';
                $this->line("  <fg=red>[WARNING] Filename: {$filename}</>");
                $this->line("  <fg=gray>          -></> <fg=red>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    // Split resolution into lines for better readability
                    $resolution_lines = explode("\n", $violation['resolution']);
                    foreach ($resolution_lines as $res_line) {
                        if (trim($res_line)) {
                            $this->line("  <fg=gray>          </> <fg=green>{$res_line}</>");
                        }
                    }
                }
            } elseif ($violation['type'] === 'unauthorized_root_file') {
                // Unauthorized files in project root
                $filename = $violation['filename'] ?? 'N/A';
                $this->line("  <fg=red>[WARNING] Unauthorized file: {$filename}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'test_file_in_rsx_root') {
                // Test files in rsx/ root directory
                $filename = $violation['filename'] ?? 'N/A';
                $this->line("  <fg=red>[WARNING] Test file in rsx/: {$filename}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'test_file_in_rsx_temp') {
                // Test files in rsx/temp directory
                $filename = $violation['filename'] ?? 'N/A';
                $this->line("  <fg=red>[WARNING] File in rsx/temp/: {$filename}</>");
                $this->line("  <fg=gray>          -></> <fg=cyan>{$violation['message']}</>");
                if (isset($violation['resolution'])) {
                    $this->line("  <fg=gray>          -> Fix:</> <fg=green>{$violation['resolution']}</>");
                }
            } elseif ($violation['type'] === 'db_table_usage') {
                // DB::table() usage violations
                $code = $violation['code'] ?? '';
                $table = isset($violation['table']) ? " (table: {$violation['table']})" : '';
                $this->line("  <fg=yellow>Line {$line}:</> <fg=red>{$code}</>");
                $this->line("  <fg=gray>          -></> <fg=red>{$violation['message']}{$table}</>");
                if (isset($violation['resolution'])) {
                    // Split resolution into lines for better readability
                    $resolution_lines = explode("\n", $violation['resolution']);
                    foreach ($resolution_lines as $res_line) {
                        if (trim($res_line)) {
                            $this->line("  <fg=gray>          </> <fg=green>{$res_line}</>");
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Output violations as JSON
     */
    protected function output_json($violations, $total_violations = null, $violations_truncated = false)
    {
        $output = [
            'violations' => $violations,
            'summary' => [
                'total_violations' => $total_violations ?: count($violations),
                'shown_violations' => count($violations),
                'violations_truncated' => $violations_truncated,
                'files_affected' => count(array_unique(array_column($violations, 'file')))
            ]
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }
    
    /**
     * Check if file is a controller
     */
    protected function is_controller_file($file_path)
    {
        // Check if filename matches controller pattern
        return preg_match('/_controller\.php$/', basename($file_path));
    }
    
    /**
     * Check if file is a model
     */
    protected function is_model_file($file_path)
    {
        // Check if file is in Models directory or ends with Model.php
        if (strpos($file_path, '/Models/') !== false || strpos($file_path, '/models/') !== false) {
            return true;
        }

        if (preg_match('/Model\.php$/', $file_path)) {
            return true;
        }

        // Quick check for extending Model
        $content = file_get_contents($file_path);
        return preg_match('/extends\s+.*Model/', $content);
    }

    /**
     * Place .placeholder files in all empty directories
     * Remove .placeholder files from non-empty directories
     */
    protected function place_placeholder_files()
    {
        $is_framework_developer = config('rsx.code_quality.is_framework_developer', false);

        // Only manage .placeholder files in framework developer mode
        // Application developers don't need them
        if (!$is_framework_developer) {
            return;
        }

        $base_path = base_path();

        // Initialize variables for \exec_safe() call
        $output = [];
        $return_code = 0;

        // Find all empty directories and place .placeholder files (exclude vendor and node_modules)
        \exec_safe("find \"$base_path\" -type d -empty -not -path '*/vendor/*' -not -path '*/node_modules/*' -exec touch {}/.placeholder \\; 2>/dev/null", $output, $return_code);

        // Find and remove zero-length .placeholder files from non-empty directories
        $directory_iterator = new \RecursiveDirectoryIterator($base_path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filter_iterator = new \RecursiveCallbackFilterIterator($directory_iterator, function ($current) {
            // Skip vendor and node_modules directories
            if ($current->isDir()) {
                $dirname = $current->getFilename();
                if ($dirname === 'vendor' || $dirname === 'node_modules') {
                    return false;
                }
            }
            return true;
        });

        $iterator = new \RecursiveIteratorIterator(
            $filter_iterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === '.placeholder') {
                $placeholder_path = $file->getPathname();

                // Check if file is zero length
                if ($file->getSize() === 0) {
                    $dir = dirname($placeholder_path);

                    // Count other files in the directory (excluding .placeholder)
                    $files = array_diff(scandir($dir), ['.', '..', '.placeholder']);

                    // If directory has other files, remove .placeholder
                    if (count($files) > 0) {
                        unlink($placeholder_path);
                    }
                }
            }
        }

        // Silently succeed - this is a maintenance operation
    }
}