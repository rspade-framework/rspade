<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Check that all Route methods in Rsx_Controller_Abstract descendants are static
 * Note: This is somewhat redundant since only static methods are extracted,
 * but provides a belt-and-suspenders check for safety
 */
class ControllerRouteStatic_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'CONTROLLER-STATIC-01';
    }

    public function get_name(): string
    {
        return 'Controller Route Methods Must Be Static';
    }

    public function get_description(): string
    {
        return 'Ensures all Route methods in RSX controllers are static';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Run during manifest build for immediate feedback
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if no class or no FQCN
        if (!isset($metadata['fqcn'])) {
            return;
        }

        // Check if this class extends Rsx_Controller_Abstract
        if (!$this->is_controller_descendant($metadata['fqcn'])) {
            return;
        }

        // Check all public static methods for Route attributes
        if (!isset($metadata['public_static_methods'])) {
            return;
        }

        foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
            // Skip if no attributes
            if (!isset($method_data['attributes'])) {
                continue;
            }

            // Check if method has Route attribute
            $has_route = false;
            foreach ($method_data['attributes'] as $attr_name => $attr_instances) {
                if ($attr_name === 'Route' || str_ends_with($attr_name, '\\Route')) {
                    $has_route = true;
                    break;
                }
            }

            // This check is redundant since we only extract public_static_methods
            // But we keep it for belt-and-suspenders safety
            if ($has_route && (!isset($method_data['static']) || !$method_data['static'])) {
                // This should never happen since manifest only extracts static methods
                shouldnt_happen("Method {$method_name} in public_static_methods is not marked as static in {$file_path}. This indicates a serious bug in metadata extraction.");
            }
        }

        // Also check for Route attributes on non-static methods by parsing the file
        // This catches the actual error case
        $this->check_non_static_routes($file_path, $contents, $metadata);
    }

    private function check_non_static_routes(string $file_path, string $contents, array $metadata): void
    {
        // Quick check if file contains Route attributes at all
        if (!preg_match('/#\[Route\b/', $contents)) {
            return;
        }

        // Parse for non-static methods with Route attributes
        $lines = explode("\n", $contents);
        $in_class = false;
        $current_attributes = [];

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);

            // Track when we're inside a class
            if (preg_match('/^class\s+\w+/', $line)) {
                $in_class = true;
            }

            if (!$in_class) {
                continue;
            }

            // Collect attributes
            if (preg_match('/^#\[(Route\b[^\]]*)\]/', $line, $matches)) {
                $current_attributes[] = $matches[1];
                continue;
            }

            // Check for method definition
            if (preg_match('/^(public|protected|private)\s+(?!static)(?:function\s+)?(\w+)\s*\(/', $line, $matches)) {
                // Non-static method found
                if (!empty($current_attributes)) {
                    // Has Route attribute but not static
                    $method_name = $matches[2];
                    $this->throw_non_static_route($file_path, $method_name, $metadata['class'] ?? 'Unknown');
                }
            }

            // Clear attributes if we hit a method or property
            if (preg_match('/^(public|protected|private)\s+/', $line)) {
                $current_attributes = [];
            }
        }
    }

    private function is_controller_descendant(string $fqcn): bool
    {
        // Check if class is loaded
        if (!class_exists($fqcn, false)) {
            // Try to load from manifest metadata
            try {
                $metadata = Manifest::php_get_metadata_by_fqcn($fqcn);
                $parent = $metadata['extends'] ?? null;

                // Walk up the inheritance chain
                while ($parent) {
                    if ($parent === 'Rsx_Controller_Abstract' ||
                        $parent === 'App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract') {
                        return true;
                    }

                    // Try to get parent's metadata
                    try {
                        $parent_metadata = Manifest::php_get_metadata_by_class($parent);
                        $parent = $parent_metadata['extends'] ?? null;
                    } catch (\Exception $e) {
                        // Parent not found in manifest
                        break;
                    }
                }
            } catch (\Exception $e) {
                // Class not found in manifest
                return false;
            }
        } else {
            // Class is loaded, use reflection
            $reflection = new \ReflectionClass($fqcn);
            return $reflection->isSubclassOf('App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract');
        }

        return false;
    }

    private function throw_non_static_route(string $file_path, string $method_name, string $class_name): void
    {
        $error_message = "==========================================\n";
        $error_message .= "FATAL: Route method must be static\n";
        $error_message .= "==========================================\n\n";
        $error_message .= "File: {$file_path}\n";
        $error_message .= "Class: {$class_name}\n";
        $error_message .= "Method: {$method_name}\n\n";
        $error_message .= "All Route methods in RSX controllers must be static.\n\n";
        $error_message .= "PROBLEM:\n";
        $error_message .= "The method '{$method_name}' has a #[Route] attribute but is not static.\n";
        $error_message .= "RSX routing system can only dispatch to static methods.\n\n";
        $error_message .= "INCORRECT:\n";
        $error_message .= "  #[Route('/users')]\n";
        $error_message .= "  public function list_users(Request \$request) { ... }\n\n";
        $error_message .= "CORRECT:\n";
        $error_message .= "  #[Route('/users')]\n";
        $error_message .= "  public static function list_users(Request \$request, array \$params = []) { ... }\n\n";
        $error_message .= "WHY THIS MATTERS:\n";
        $error_message .= "- RSX controllers are never instantiated\n";
        $error_message .= "- Routes are dispatched directly to static methods\n";
        $error_message .= "- This allows stateless, efficient request handling\n\n";
        $error_message .= "FIX:\n";
        $error_message .= "Add the 'static' keyword to the method definition.\n";
        $error_message .= "==========================================";

        throw new YoureDoingItWrongException($error_message);
    }
}