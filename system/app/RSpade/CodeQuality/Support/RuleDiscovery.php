<?php

namespace App\RSpade\CodeQuality\Support;

use App\RSpade\CodeQuality\Support\ViolationCollector;

/**
 * Shared rule discovery logic for CodeQualityChecker and Manifest
 *
 * This allows both systems to discover and instantiate rules without
 * requiring the CodeQuality directory to be part of the manifest scan.
 */
class RuleDiscovery
{
    /**
     * Discover and load all code quality rules
     *
     * @param ViolationCollector $collector The violation collector to pass to rules
     * @param array $config Configuration to pass to rules
     * @param bool $only_manifest_scan If true, only return rules with is_called_during_manifest_scan() = true
     * @param bool $exclude_manifest_scan If true, exclude rules with is_called_during_manifest_scan() = true
     * @return array Array of instantiated rule objects
     */
    public static function discover_rules(ViolationCollector $collector, array $config = [], bool $only_manifest_scan = false, bool $exclude_manifest_scan = false): array
    {
        $rules = [];
        $rules_dir = base_path('app/RSpade/CodeQuality/Rules');

        // Scan Rules directory for rule classes
        $rule_files = glob($rules_dir . '/**/*.php', GLOB_BRACE);

        foreach ($rule_files as $file_path) {
            // Skip abstract rule base class itself
            if (str_ends_with($file_path, 'CodeQualityRule_Abstract.php')) {
                continue;
            }

            // Extract class metadata without loading the file
            $metadata = static::extract_class_metadata($file_path);

            if (!isset($metadata['class'])) {
                continue;
            }

            // Build FQCN
            $fqcn = $metadata['fqcn'] ?? null;
            if (!$fqcn) {
                continue;
            }

            // Check if it extends CodeQualityRule_Abstract
            if (!isset($metadata['extends']) || $metadata['extends'] !== 'CodeQualityRule_Abstract') {
                continue;
            }

            // Load the file
            require_once $file_path;

            // Check if class exists (sanity check)
            if (!class_exists($fqcn)) {
                // Try with full namespace if it's not found
                // This handles cases where the class is in a subdirectory
                $relative_path = str_replace(base_path() . '/', '', $file_path);
                $relative_path = str_replace('.php', '', $relative_path);
                $relative_path = str_replace('/', '\\', $relative_path);
                $fqcn = '\\' . ucfirst($relative_path);

                if (!class_exists($fqcn)) {
                    shouldnt_happen(
                        "CodeQuality rule class '{$fqcn}' found in file '{$file_path}' but class_exists() failed after require_once"
                    );
                }
            }

            // Instantiate the rule
            $rule = new $fqcn($collector, $config);

            // Check if rule is enabled
            if (!$rule->is_enabled()) {
                continue;
            }

            // Filter based on manifest scan requirements
            $is_manifest_scan_rule = $rule->is_called_during_manifest_scan();

            if ($only_manifest_scan && !$is_manifest_scan_rule) {
                continue; // Skip non-manifest-scan rules when only wanting manifest-scan rules
            }

            if ($exclude_manifest_scan && $is_manifest_scan_rule) {
                continue; // Skip manifest-scan rules when excluding them (e.g., during rsx:check)
            }

            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * Extract basic class metadata from a PHP file using token parsing
     * This is a simplified version that doesn't require Manifest
     *
     * @param string $file_path Path to the PHP file
     * @return array Metadata array with namespace, class, fqcn, extends
     */
    protected static function extract_class_metadata(string $file_path): array
    {
        $content = file_get_contents($file_path);
        $tokens = token_get_all($content);

        $metadata = [];
        $namespace = '';
        $class = '';
        $extends = '';

        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            // Look for namespace
            if ($tokens[$i][0] === T_NAMESPACE) {
                $i++;
                while ($i < $count && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }
                // PHP 8.4+ uses T_NAME_QUALIFIED for fully qualified names
                if ($i < $count && (defined('T_NAME_QUALIFIED') && $tokens[$i][0] === T_NAME_QUALIFIED)) {
                    $namespace = $tokens[$i][1];
                    $i++;
                } else {
                    // Fallback for older PHP versions
                    while ($i < $count && ($tokens[$i][0] === T_STRING || $tokens[$i][0] === T_NS_SEPARATOR)) {
                        $namespace .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                        $i++;
                    }
                }
            }

            // Look for class
            if ($tokens[$i][0] === T_CLASS) {
                $i++;
                while ($i < $count && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }
                if ($i < $count && $tokens[$i][0] === T_STRING) {
                    $class = $tokens[$i][1];
                    $i++;

                    // Look for extends
                    while ($i < $count && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;
                    }
                    if ($i < $count && $tokens[$i][0] === T_EXTENDS) {
                        $i++;
                        while ($i < $count && $tokens[$i][0] === T_WHITESPACE) {
                            $i++;
                        }
                        if ($i < $count && $tokens[$i][0] === T_STRING) {
                            $extends = $tokens[$i][1];
                        }
                    }
                    break; // Found the class, stop parsing
                }
            }

            $i++;
        }

        if ($class) {
            $metadata['class'] = $class;
            $metadata['namespace'] = $namespace;
            $metadata['fqcn'] = $namespace ? $namespace . '\\' . $class : $class;
            $metadata['extends'] = $extends;
        }

        return $metadata;
    }
}