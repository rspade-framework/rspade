<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class MassAssignment_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-MASS-01';
    }

    public function get_name(): string
    {
        return 'Mass Assignment Property Check';
    }

    public function get_description(): string
    {
        return 'Prohibits use of $fillable and enforces $guarded = ["*"] in models';
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
     * mass assignment protection is critical for security and must be enforced immediately.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    /**
     * Process file during manifest update to extract mass assignment metadata
     */
    public function on_manifest_file_update(string $file_path, string $contents, array $metadata = []): ?array
    {

        // Skip Model_Abstract itself
        if (str_contains($file_path, 'Model_Abstract.php')) {
            return null;
        }

        // Skip Rsx_Model_Abstract.php - it needs empty $fillable to satisfy Laravel requirements
        if (str_contains($file_path, 'Rsx_Model_Abstract.php')) {
            return null;
        }

        // Skip if not a model file
        if (!isset($metadata['class'])) {
            return null;
        }


        // Check if this is a model using proper inheritance check
        if (!Manifest::php_is_subclass_of($metadata['class'], 'Rsx_Model_Abstract')) {
            return null; // Not a model class
        }

        // Parse the file to check for mass assignment properties
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($contents);
            if (!$ast) {
                return null;
            }
        } catch (\Exception $e) {
            return null;
        }

        $nodeFinder = new NodeFinder();
        $properties = $nodeFinder->findInstanceOf($ast, Node\Stmt\Property::class);

        $violations = [];

        foreach ($properties as $property) {
            foreach ($property->props as $prop) {
                $prop_name = $prop->name->toString();

                // Check for $fillable property
                if ($prop_name === 'fillable') {
                    $violations[] = [
                        'type' => 'fillable',
                        'line' => $property->getLine(),
                        'property' => $prop_name
                    ];
                }

                // Check for $guarded property (unless it's set to ['*'])
                if ($prop_name === 'guarded') {
                    $is_star_guarded = false;
                    if ($prop->default instanceof Node\Expr\Array_) {
                        if (count($prop->default->items) === 1) {
                            $item = $prop->default->items[0];
                            if ($item && $item->value instanceof Node\Scalar\String_ && $item->value->value === '*') {
                                $is_star_guarded = true;
                            }
                        }
                    }

                    if (!$is_star_guarded) {
                        $violations[] = [
                            'type' => 'guarded',
                            'line' => $property->getLine(),
                            'property' => $prop_name
                        ];
                    }
                }
            }
        }

        if (!empty($violations)) {
            return ['mass_assignment_violations' => $violations];
        }

        return null;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip Model_Abstract itself
        if (str_contains($file_path, 'Model_Abstract.php')) {
            return;
        }

        // Skip Rsx_Model_Abstract.php - it needs empty $fillable to satisfy Laravel requirements
        if (str_contains($file_path, 'Rsx_Model_Abstract.php')) {
            return;
        }

        // Skip if not a model file
        if (!isset($metadata['class'])) {
            return;
        }

        // Check if this is a model using proper inheritance check
        if (!Manifest::php_is_subclass_of($metadata['class'], 'Rsx_Model_Abstract')) {
            return; // Not a model class
        }

        // Check for mass assignment violations in code quality metadata
        if (isset($metadata['code_quality_metadata']['PHP-MASS-01']['mass_assignment_violations'])) {
            $violations = $metadata['code_quality_metadata']['PHP-MASS-01']['mass_assignment_violations'];

            // Throw on first violation
            foreach ($violations as $violation) {
                $type = $violation['type'];
                $line = $violation['line'];
                $class_name = $metadata['class'];

                if ($type === 'fillable') {
                    $error_message = "Code Quality Violation (PHP-MASS-01) - Prohibited Mass Assignment Property\n\n";
                    $error_message .= "Model class '{$class_name}' has a \$fillable property\n\n";
                    $error_message .= "File: {$file_path}\n";
                    $error_message .= "Line: {$line}\n\n";
                    $error_message .= "CRITICAL: Mass assignment is prohibited in RSX.\n\n";
                    $error_message .= "Resolution:\n";
                    $error_message .= "Remove the \$fillable property and assign fields explicitly:\n";
                    $error_message .= "\$model->field = \$value;\n";
                    $error_message .= "\$model->save();\n\n";
                    $error_message .= "This ensures data integrity and security by requiring explicit field assignment.";
                } else {
                    $error_message = "Code Quality Violation (PHP-MASS-01) - Incorrect Guard Configuration\n\n";
                    $error_message .= "Model class '{$class_name}' has a customized \$guarded property\n\n";
                    $error_message .= "File: {$file_path}\n";
                    $error_message .= "Line: {$line}\n\n";
                    $error_message .= "CRITICAL: The \$guarded property should not be customized.\n\n";
                    $error_message .= "Resolution:\n";
                    $error_message .= "Remove the \$guarded property entirely.\n";
                    $error_message .= "Model_Abstract handles mass assignment protection automatically.";
                }

                throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                    $error_message,
                    0,
                    null,
                    $file_path,
                    $line
                );
            }
        }
    }
}
