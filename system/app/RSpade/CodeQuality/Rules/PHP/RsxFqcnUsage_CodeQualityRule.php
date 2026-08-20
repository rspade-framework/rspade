<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Enforces path-agnostic class references by preventing direct FQCN usage starting with \Rsx\
 *
 * RSX classes should be referenced by simple name only, allowing the autoloader to
 * resolve them automatically. Direct FQCN references like \Rsx\Models\User_Model
 * are not allowed - use User_Model instead.
 *
 * Note: use statements for Rsx\ classes ARE allowed - this rule only prevents
 * direct FQCN usage in code like new \Rsx\Models\User_Model() or \App\RSpade\Core\Session\Session::init()
 */
class RsxFqcnUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-RSX-FQCN-01';
    }

    public function get_name(): string
    {
        return 'RSX FQCN Usage Validator';
    }

    public function get_description(): string
    {
        return 'Prevents direct FQCN references starting with \Rsx\ to maintain path-agnostic class loading';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * This rule runs during manifest scan to check for FQCN violations
     * detected by the PHP AST parser
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Main check method - processes all PHP files from manifest
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only process during manifest-time when we have all files
        static $already_run = false;
        if ($already_run) {
            return;
        }

        // On the first PHP file, process all files
        if (!empty($metadata) && $metadata['extension'] === 'php') {
            $this->process_all_files();
            $already_run = true;
        }
    }

    private function process_all_files(): void
    {
        // Get all files from manifest
        $files = Manifest::get_all();

        foreach ($files as $file_path => $metadata) {
            // Skip non-PHP files
            if (($metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            // Check for FQCN violations detected by the PHP AST parser
            if (isset($metadata['rsx_fqcn_violations'])) {
                foreach ($metadata['rsx_fqcn_violations'] as $violation) {
                    $line = $violation['line'] ?? 0;
                    $fqcn = $violation['fqcn'] ?? 'unknown';

                    // Extract the simple class name from the FQCN
                    $parts = explode('\\', trim($fqcn, '\\'));
                    $simple_name = end($parts);

                    $message = "Direct FQCN reference '{$fqcn}' is not allowed. RSX classes are path-agnostic and should be referenced by simple name only.";
                    $suggestion = "Replace '{$fqcn}' with '{$simple_name}'. The autoloader will automatically resolve the class.";

                    // Get the code snippet if we can access the file
                    $code_snippet = '';
                    if (file_exists($file_path)) {
                        $file_contents = file_get_contents($file_path);
                        $lines = explode("\n", $file_contents);
                        if ($line > 0 && $line <= count($lines)) {
                            $code_snippet = trim($lines[$line - 1]);
                        }
                    }

                    $this->add_violation(
                        $file_path,
                        $line,
                        $message,
                        $code_snippet,
                        $suggestion,
                        'high'
                    );
                }
            }
        }
    }
}