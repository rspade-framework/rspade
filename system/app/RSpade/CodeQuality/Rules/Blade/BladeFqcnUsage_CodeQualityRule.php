<?php

namespace App\RSpade\CodeQuality\Rules\Blade;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Enforces path-agnostic class references in Blade templates by preventing direct FQCN usage
 *
 * Blade templates should reference RSX classes by simple name only, not by FQCN.
 * Direct references like \Rsx\Models\User_Model or \App\RSpade\Core\Session\Session::method()
 * are not allowed - use User_Model or Session instead.
 *
 * Note: use statements for Rsx\ classes ARE allowed in PHP blocks within Blade files,
 * though they are unnecessary due to the autoloader.
 */
class BladeFqcnUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'BLADE-RSX-FQCN-01';
    }

    public function get_name(): string
    {
        return 'Blade RSX FQCN Usage Validator';
    }

    public function get_description(): string
    {
        return 'Prevents direct FQCN references to Rsx classes in Blade templates';
    }

    public function get_file_patterns(): array
    {
        return ['*.blade.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Pattern to match \Rsx\... FQCN references
        // Looks for \Rsx\ followed by class path components
        // Must be preceded and followed by non-alphanumeric characters (except \ for namespace)
        $pattern = '/(?<![a-zA-Z0-9])\\\\Rsx\\\\[a-zA-Z0-9_\\\\]+/';

        if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        // Get manifest data once for lookups
        $manifest = Manifest::get_all();

        foreach ($matches[0] as $match) {
            $fqcn = $match[0];
            $offset = $match[1];

            // Clean up the FQCN - remove leading backslash and normalize
            $clean_fqcn = ltrim($fqcn, '\\');

            // Extract simple class name from FQCN
            $parts = explode('\\', $clean_fqcn);
            $simple_name = end($parts);

            // Check if this FQCN actually exists in the manifest
            // This prevents false positives for non-existent classes
            $class_exists = false;

            try {
                // Try to find the class by simple name
                $class_metadata = Manifest::php_get_metadata_by_class($simple_name);

                // Verify the FQCN matches
                if ($class_metadata && isset($class_metadata['fqcn'])) {
                    $manifest_fqcn = $class_metadata['fqcn'];
                    // Compare without leading backslash
                    if (ltrim($manifest_fqcn, '\\') === $clean_fqcn) {
                        $class_exists = true;
                    }
                }
            } catch (\RuntimeException $e) {
                // Class not found in manifest - not a real class reference
                continue;
            }

            // Only report violation if the class actually exists
            if (!$class_exists) {
                continue;
            }

            // Calculate line number from offset
            $line = 1;
            for ($i = 0; $i < $offset; $i++) {
                if ($contents[$i] === "\n") {
                    $line++;
                }
            }

            // Extract the line containing the violation for context
            $lines = explode("\n", $contents);
            $code_snippet = '';
            if ($line > 0 && $line <= count($lines)) {
                $code_snippet = trim($lines[$line - 1]);
            }

            $message = "Direct FQCN reference '{$fqcn}' in Blade template is not allowed. RSX classes are path-agnostic and should be referenced by simple name only.";
            $suggestion = "Replace '{$fqcn}' with '{$simple_name}'. The autoloader will automatically resolve the class.";

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