<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * ModelFetchAuthCheckRule - Structural checks on a model's ORM-exposed surface.
 *
 * AUTHORIZATION IS DECLARED, NOT DETECTED. Whether a fetch surface is gated is no
 * longer this rule's business: every #[Ajax_Endpoint_Model_Fetch] method must carry
 * an #[Auth(...)] gate (its own or its class's), and the manifest build FAILS by
 * name when one does not (Auth_ManifestSupport's validation pass). There is nothing
 * left to pattern-match, so the body scanning this rule used to do - and the
 * @auth-exempt docblock it used to honor - are gone.
 *
 * What remains is structural, and orthogonal to gates: a model-borne
 * #[Ajax_Endpoint]. That attribute only means something on an Rsx_Controller_Abstract
 * subclass, so on a model it is unreachable dead security metadata - it reads as a
 * live, audited endpoint while enforcing and exposing nothing.
 *
 * See: php artisan rsx:man auth_gates
 */
class ModelFetchAuthCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'PHP-MODEL-FETCH-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'Model ORM Surface Structure';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Flags a model-borne #[Ajax_Endpoint], which is unreachable dead security metadata';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*_model.php', '*_Model.php'];
    }

    /**
     * Whether this rule is called during manifest scan
     */
    public function is_called_during_manifest_scan(): bool
    {
        return false; // Only run during rsx:check
    }

    /**
     * Get default severity for this rule
     */
    public function get_default_severity(): string
    {
        return 'medium';
    }

    /**
     * Check a file for violations
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Cheap skip first: a class with no parent at all can never be a model
        if (empty($metadata['extends'])) {
            return;
        }

        // Skip archived files
        if (str_contains($file_path, '/archive/') || str_contains($file_path, '/archived/')) {
            return;
        }

        // Get the class name
        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        // Only check model files. The lineage is walked (not an immediate-parent string
        // compare) so a model sitting under an intermediate abstract - e.g. the
        // site-scoped Rsx_Site_Model_Abstract - is still checked.
        if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            return;
        }

        $this->check_model_borne_ajax_endpoints(
            $file_path,
            $class_name,
            $metadata['public_static_methods'] ?? []
        );
    }

    /**
     * Flag #[Ajax_Endpoint] attributes declared on a model class
     *
     * Both Ajax entry points resolve the target class through Ajax::handle_browser_request()
     * (the /_ajax/:controller/:action route) and Ajax::internal() (the batch route), and
     * both refuse anything that is not a Rsx_Controller_Abstract subclass, so an
     * #[Ajax_Endpoint] on a model is never reachable. The attribute reads
     * as a live, audited endpoint while enforcing nothing - dead security metadata that
     * misleads every later reader and every audit.
     */
    private function check_model_borne_ajax_endpoints(string $file_path, string $class_name, array $methods): void
    {
        foreach ($methods as $method_name => $method_info) {
            $attributes = $method_info['attributes'] ?? [];

            foreach ($attributes as $attr_name => $attr_data) {
                $short_name = basename(str_replace('\\', '/', $attr_name));

                if ($short_name !== 'Ajax_Endpoint') {
                    continue;
                }

                $this->add_violation(
                    $file_path,
                    $method_info['line'] ?? 1,
                    "Model method '{$method_name}' declares #[Ajax_Endpoint], which has no effect on a model class",
                    "#[Ajax_Endpoint]\npublic static function {$method_name}(...)",
                    $this->build_model_endpoint_suggestion($class_name, $method_name),
                    'medium'
                );

                break;
            }
        }
    }

    /**
     * Build suggestion for a model-borne #[Ajax_Endpoint]
     */
    private function build_model_endpoint_suggestion(string $class_name, string $method_name): string
    {
        $suggestions = [];
        $suggestions[] = "#[Ajax_Endpoint] is a CONTROLLER attribute and is silently ignored on a model.";
        $suggestions[] = "";
        $suggestions[] = "WHY THIS IS A VIOLATION:";
        $suggestions[] = "The Ajax dispatcher resolves the requested class and rejects anything that is";
        $suggestions[] = "not a Rsx_Controller_Abstract subclass, so {$class_name}::{$method_name}() is not";
        $suggestions[] = "callable from JavaScript. The attribute declares an endpoint that does not exist,";
        $suggestions[] = "and dead security metadata is worse than none: it reads as an audited, enforced";
        $suggestions[] = "surface to every later reader.";
        $suggestions[] = "";
        $suggestions[] = "Option 1: If JavaScript needs to read this record, expose the ORM fetch surface:";
        $suggestions[] = "    #[Ajax_Endpoint_Model_Fetch]";
        $suggestions[] = "    #[Auth('is_logged_in')]";
        $suggestions[] = "    public static function fetch(\$id) { ... }   // record-level rules in the body";
        $suggestions[] = "";
        $suggestions[] = "Option 2: If JavaScript needs to call this operation, move it to a controller:";
        $suggestions[] = "    class My_Controller extends Rsx_Controller_Abstract {";
        $suggestions[] = "        #[Ajax_Endpoint]";
        $suggestions[] = "        #[Auth('is_logged_in')]";
        $suggestions[] = "        public static function {$method_name}(Request \$request, array \$params = []) { ... }";
        $suggestions[] = "    }";
        $suggestions[] = "";
        $suggestions[] = "Option 3: If the method is server-side only, delete the attribute.";
        $suggestions[] = "";
        $suggestions[] = "See: php artisan rsx:man auth_gates";

        return implode("\n", $suggestions);
    }
}
