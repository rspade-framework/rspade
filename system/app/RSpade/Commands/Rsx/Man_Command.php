<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;

class Man_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:man {term? : The documentation term to look up (partial matches allowed)} {--agent-helper-message : Output agent helper message with available topics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display RSX documentation from man pages (pretty formatted)';

    /**
     * The documentation directory paths.
     *
     * @var array
     */
    protected $docs_dirs;

    /**
     * Create a new command instance.
     *
     * Directories are listed in priority order - first match wins for same-named files.
     * Project-specific docs in rsx/resource/man override framework docs in app/RSpade/man.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        // Priority order: project-specific > user docs > framework docs
        $this->docs_dirs = [
            base_path('../rsx/resource/man'),   // Project-specific (highest priority)
            base_path('docs/man'),               // User docs
            base_path('app/RSpade/man'),         // Framework docs (lowest priority)
        ];
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Check if agent helper message was requested
        if ($this->option('agent-helper-message')) {
            return $this->show_agent_helper_message();
        }

        // Create docs/man directory if it doesn't exist (first dir only)
        if (!is_dir($this->docs_dirs[0])) {
            mkdir($this->docs_dirs[0], 0755, true);
        }

        $term = $this->argument('term');

        // If no term provided, list all available documentation
        if (!$term) {
            return $this->list_all_documentation();
        }

        // Find matching documentation files
        $matches = $this->find_matching_files($term);

        if (count($matches) === 0) {
            $this->error("No documentation found for '{$term}'");
            $this->line('');
            $this->line('Available documentation:');
            $this->list_all_documentation();
            return 1;
        }

        if (count($matches) === 1) {
            // Display the single matching file
            return $this->display_documentation($matches[0]);
        }

        // Multiple matches found - list them
        $this->warn("Multiple matches found for '{$term}':");
        $this->line('');
        foreach ($matches as $file) {
            $name = basename($file, '.txt');
            $this->line("  - <info>{$name}</info>");
        }
        $this->line('');
        $this->line('Please specify a more specific term.');
        return 1;
    }

    /**
     * List all available documentation files.
     *
     * @return int
     */
    protected function list_all_documentation()
    {
        $files = $this->get_all_files_deduplicated();

        if (empty($files)) {
            $this->warn('No documentation files found in:');
            foreach ($this->docs_dirs as $dir) {
                $this->line('  - ' . $dir);
            }
            return 1;
        }

        $this->info('Available Documentation:');
        $this->line('');

        $docs = [];
        foreach ($files as $file) {
            $name = basename($file, '.txt');
            // Read file to extract description from NAME section
            $content = file_get_contents($file);
            $description = '';

            // Look for NAME section in man page format
            if (preg_match('/^NAME\s*\n\s*(.+?)(?:\n|$)/m', $content, $matches)) {
                $description = trim($matches[1]);
                // Remove the name part, keep just description after dash
                if (str_contains($description, ' - ')) {
                    $parts = explode(' - ', $description, 2);
                    $description = trim($parts[1]);
                }
            } elseif (preg_match('/^#\s*(.+?)(?:\n|$)/m', $content, $matches)) {
                // @PHP-FALLBACK-01-EXCEPTION - Fallback* parsing for man page description extraction
                // Fallback to first header line
                $description = trim($matches[1]);
            } else {
                // Fallback to first non-empty line
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!empty($lines)) {
                    foreach ($lines as $line) {
                        if (!str_starts_with($line, '#') && !str_starts_with($line, '=')) {
                            $description = trim($line);
                            break;
                        }
                    }
                }
            }

            if (strlen($description) > 60) {
                $description = substr($description, 0, 57) . '...';
            }
            $docs[] = ['name' => $name, 'description' => $description];
        }

        // Sort alphabetically
        usort($docs, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // Display in a nice table format
        foreach ($docs as $doc) {
            $this->line(sprintf("  <info>%-25s</info> %s", $doc['name'], $doc['description']));
        }

        $this->line('');
        $this->line('Usage: <comment>php artisan rsx:man <term></comment>');

        return 0;
    }

    /**
     * Get all documentation files, deduplicated by filename.
     * Files from earlier directories in docs_dirs take precedence.
     * Excludes .expect files (behavioral expectation documentation for testing).
     *
     * @return array
     */
    protected function get_all_files_deduplicated(): array
    {
        $files_by_name = [];
        foreach ($this->docs_dirs as $dir) {
            if (is_dir($dir)) {
                $dir_files = glob($dir . '/*.txt');
                if ($dir_files) {
                    foreach ($dir_files as $file) {
                        // Skip .expect files (behavioral expectation docs)
                        if (str_ends_with($file, '.expect')) {
                            continue;
                        }
                        $name = basename($file, '.txt');
                        // First occurrence wins (higher priority directory)
                        if (!isset($files_by_name[$name])) {
                            $files_by_name[$name] = $file;
                        }
                    }
                }
            }
        }
        return array_values($files_by_name);
    }

    /**
     * Find documentation files matching the given term.
     *
     * @param string $term
     * @return array
     */
    protected function find_matching_files(string $term): array
    {
        $files = $this->get_all_files_deduplicated();

        $term_lower = strtolower($term);
        $matches = [];

        foreach ($files as $file) {
            $filename = basename($file, '.txt');
            $filename_lower = strtolower($filename);

            // Exact match (case-insensitive)
            if ($filename_lower === $term_lower) {
                return [$file]; // Return immediately for exact match
            }

            // Partial match
            if (str_contains($filename_lower, $term_lower)) {
                $matches[] = $file;
            }
        }

        // If no partial matches, try fuzzy matching with underscores/hyphens
        if (empty($matches)) {
            $term_normalized = str_replace(['-', '_', ' '], '', $term_lower);

            foreach ($files as $file) {
                $filename = basename($file, '.txt');
                $filename_normalized = str_replace(['-', '_', ' '], '', strtolower($filename));

                if (str_contains($filename_normalized, $term_normalized)) {
                    $matches[] = $file;
                }
            }
        }

        return $matches;
    }

    /**
     * Display the contents of a documentation file with pretty formatting.
     *
     * @param string $file_path
     * @return int
     */
    protected function display_documentation(string $file_path): int
    {
        if (!file_exists($file_path)) {
            $this->error("Documentation file not found: {$file_path}");
            return 1;
        }

        $content = file_get_contents($file_path);

        // Check if this is a man page format
        $is_man_page = preg_match('/^[A-Z_]+\(\d+\)/', $content);

        $lines = explode("\n", $content);

        $this->newLine();

        $in_code_block = false;
        $in_section = false;
        $in_example = false;

        foreach ($lines as $line) {
            // Skip man page headers/footers. Both forms end in a TOPIC(N) token
            // after a wide column gap: the opening title also STARTS with TOPIC(N)
            // (TOPIC(N)  Manual  TOPIC(N)), while the closing footer starts with the
            // issuer/date instead (RSX Framework  <date>  TOPIC(N)). Anchoring on the
            // trailing token + the multi-space column gap catches both without eating
            // prose or comma-separated SEE ALSO lines that merely contain a foo(3) ref.
            if ($is_man_page && preg_match('/^\S.*\s{2,}[A-Z_]+\(\d+\)$/', $line)) {
                continue;
            }

            // Detect code examples in man pages (indented 4+ spaces)
            if ($is_man_page && !$in_code_block) {
                if (preg_match('/^    /', $line)) {
                    // Just display indented code without boxes
                    $this->line('    ' . substr($line, 4));
                    continue;
                }
            }
            // Handle code blocks
            if (str_starts_with($line, '```')) {
                $in_code_block = !$in_code_block;
                continue;
            }

            if ($in_code_block) {
                $this->line('    ' . $line);
                continue;
            }

            // Handle man page section headers (all caps)
            if ($is_man_page && preg_match('/^[A-Z][A-Z\s_]+$/', $line) && strlen($line) < 40) {
                if ($in_section) {
                    $this->newLine();
                }
                $this->line(strtoupper($line));
                $in_section = true;
                continue;
            }

            // Handle headers
            if (str_starts_with($line, '# ')) {
                $header = substr($line, 2);
                $this->newLine();
                $this->line(strtoupper($header));
                $this->newLine();
                $in_section = true;
                continue;
            }

            if (str_starts_with($line, '## ')) {
                $header = substr($line, 3);
                if ($in_section) {
                    $this->newLine();
                }
                $this->line($header);
                continue;
            }

            if (str_starts_with($line, '### ')) {
                $header = substr($line, 4);
                $this->line($header);
                continue;
            }

            // Handle lists
            if (preg_match('/^(\s*)[•\-\*]\s+(.+)/', $line, $matches)) {
                $indent = str_repeat(' ', strlen($matches[1]));
                $this->line($indent . '- ' . $matches[2]);
                continue;
            }

            // Handle numbered lists
            if (preg_match('/^(\s*)(\d+)\.\s+(.+)/', $line, $matches)) {
                $indent = str_repeat(' ', strlen($matches[1]));
                $this->line($indent . $matches[2] . '. ' . $matches[3]);
                continue;
            }

            // Handle separator lines
            if (preg_match('/^[\-=]{3,}$/', $line)) {
                $this->line('');
                continue;
            }

            // Regular text
            if (trim($line) === '') {
                $this->line('');
            } else {
                $this->line($line);
            }
        }

        $this->newLine();

        return 0;
    }

    /**
     * Show agent helper message with available topics
     *
     * @return int
     */
    protected function show_agent_helper_message(): int
    {
        $this->line('Comprehensive documentation is available for the following commands, and can be reviewed by calling "php artisan rsx:man (topic)". This text should be referenced whenever doing advanced integration work with these subsections to get necessary context on how the subsystems work before proceeding with edits, implementations, or generally using the subsystems.');
        $this->line('');

        // Get all available topics
        $topics = $this->get_all_topic_names();
        $this->line(implode(' ', $topics));

        $this->line('');

        return 0;
    }

    /**
     * Get all available topic names
     *
     * @return array
     */
    protected function get_all_topic_names(): array
    {
        $files = $this->get_all_files_deduplicated();

        $topics = [];
        foreach ($files as $file) {
            $topics[] = basename($file, '.txt');
        }

        sort($topics);
        return $topics;
    }
}