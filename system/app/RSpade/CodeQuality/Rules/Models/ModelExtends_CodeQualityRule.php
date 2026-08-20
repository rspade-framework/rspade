<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class ModelExtends_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MODEL-EXTENDS-01';
    }

    public function get_name(): string
    {
        return 'Model Must Extend Rsx_Model_Abstract';
    }

    public function get_description(): string
    {
        return 'All models must extend Rsx_Model_Abstract, not Model directly';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Whether this rule is called during manifest scan
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * models must extend the correct base class for the framework's ORM to function.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check PHP files in /rsx/ directory
        if (!str_contains($file_path, '/rsx/')) {
            return;
        }

        // Get class name from metadata if available
        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        // Skip if it's Rsx_Model_Abstract itself or abstract class
        if ($class_name === 'Rsx_Model_Abstract' || (isset($metadata['abstract']) && $metadata['abstract'])) {
            return;
        }

        // Check if this class is a subclass of Model
        if (!Manifest::php_is_subclass_of($class_name, 'Model')) {
            return; // Not a model at all
        }

        // Now check if it extends Rsx_Model_Abstract
        if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            // Get line number where class is defined
            $lines = explode("\n", $contents);
            $line_number = 1;
            foreach ($lines as $i => $line) {
                if (preg_match('/\bclass\s+' . preg_quote($class_name) . '\b/', $line)) {
                    $line_number = $i + 1;
                    break;
                }
            }

            $error_message = "Code Quality Violation (MODEL-EXTENDS-01) - Incorrect Model Inheritance\n\n";
            $error_message .= "Model class '{$class_name}' extends Model directly instead of Rsx_Model_Abstract\n\n";
            $error_message .= "File: {$file_path}\n";
            $error_message .= "Line: {$line_number}\n\n";
            $error_message .= "CRITICAL: All models in /rsx/ MUST extend Rsx_Model_Abstract for proper framework functionality.\n\n";
            $error_message .= "Resolution:\nChange the class declaration to:\n";
            $error_message .= "class {$class_name} extends Rsx_Model_Abstract\n\n";
            $error_message .= "This ensures mass assignment protection, proper serialization, and framework integration.";

            throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                $error_message,
                0,
                null,
                $file_path,
                $line_number
            );
        }
    }
}
