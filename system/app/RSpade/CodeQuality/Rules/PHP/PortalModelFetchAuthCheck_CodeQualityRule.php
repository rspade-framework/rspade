<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * PortalModelFetchAuthCheckRule - Validates the RECORD-LEVEL contract of a model
 * exposed to the portal ORM.
 *
 * AUTHORIZATION IS DECLARED, NOT DETECTED. Whether portal_fetch() is gated is no
 * longer this rule's business: the surface must carry an #[Auth(...)] gate naming a
 * portal-realm check, and the manifest build FAILS by name when it does not
 * (Auth_ManifestSupport's validation pass). The portal auth-check pattern matching
 * and the @portal-auth-exempt docblock this rule used to honor are therefore gone.
 *
 * What remains is the layer the gates deliberately do NOT cover. A gate answers
 * "may this user use this surface at all"; portal_can_read() answers "may they see
 * THIS ROW", and it is the framework's fail-closed per-record contract. So a model
 * exposing portal_fetch() with #[Ajax_Endpoint_Model_Fetch] MUST:
 *   - declare portal_can_read(), and
 *   - if it defines portal_fetch() itself rather than using the Portal_Authorizable
 *     trait, actually call portal_can_read() from that body.
 *
 * The standard pattern is `use Portal_Authorizable;` (the trait supplies a
 * portal_fetch() that defers to portal_can_read()) plus portal_can_read() on the
 * model; then only the declaration is checked here.
 *
 * See: php artisan rsx:man portal, php artisan rsx:man auth_gates
 */
class PortalModelFetchAuthCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PORTAL-MODEL-FETCH-01';
    }

    public function get_name(): string
    {
        return 'Portal Model Fetch Record-Level Contract';
    }

    public function get_description(): string
    {
        return 'Validates that models exposing portal_fetch() declare and use portal_can_read()';
    }

    public function get_file_patterns(): array
    {
        return ['*_model.php', '*_Model.php'];
    }

    public function is_called_during_manifest_scan(): bool
    {
        return false; // Only run during rsx:check
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check model files (must extend Rsx_Model_Abstract)
        if (!isset($metadata['extends']) || $metadata['extends'] !== 'Rsx_Model_Abstract') {
            return;
        }

        // Skip archived files
        if (str_contains($file_path, '/archive/') || str_contains($file_path, '/archived/')) {
            return;
        }

        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        $methods = $metadata['public_static_methods'] ?? [];

        // The model must expose a portal_fetch() with the fetch attribute to be
        // portal-fetchable. If it doesn't, this rule does not apply.
        if (!isset($methods['portal_fetch'])) {
            return;
        }

        $portal_fetch_info = $methods['portal_fetch'];
        $attributes = $portal_fetch_info['attributes'] ?? [];

        $has_fetch_attribute = false;
        foreach ($attributes as $attr_name => $attr_data) {
            $short_name = basename(str_replace('\\', '/', $attr_name));
            if ($short_name === 'Ajax_Endpoint_Model_Fetch') {
                $has_fetch_attribute = true;
                break;
            }
        }

        if (!$has_fetch_attribute) {
            return;
        }

        $line_number = $portal_fetch_info['line'] ?? 1;

        // Whether portal_fetch is supplied by a trait (Portal_Authorizable) or
        // defined in the model file itself. Trait methods carry a 'file' key.
        $is_trait_provided = isset($portal_fetch_info['file'])
            && rsxrealpath($portal_fetch_info['file']) !== rsxrealpath($file_path);

        // Every portal-fetchable model must declare portal_can_read() - the per-row,
        // fail-closed visibility rule the framework relies on.
        if (!$this->model_defines_portal_can_read($contents)) {
            $this->add_violation(
                $file_path,
                $line_number,
                "Model exposes portal_fetch() but does not define portal_can_read()",
                "#[Ajax_Endpoint_Model_Fetch]\npublic static function portal_fetch(\$id)",
                $this->build_can_read_suggestion(),
                'high'
            );
            return;
        }

        // If portal_fetch lives in the trait, the trait calls portal_can_read();
        // nothing more to verify in this file.
        if ($is_trait_provided) {
            return;
        }

        // The model defines portal_fetch() itself: its body must actually consult the
        // record-level rule it declares. A declared-but-uncalled portal_can_read() is
        // the same dead-security-metadata failure as an unreachable endpoint.
        $method_body = $this->extract_method_body($contents, 'portal_fetch');
        if ($method_body === null) {
            return;
        }

        if (str_contains($method_body, 'portal_can_read')) {
            return;
        }

        $this->add_violation(
            $file_path,
            $line_number,
            "Model portal_fetch() does not call portal_can_read()",
            "#[Ajax_Endpoint_Model_Fetch]\npublic static function portal_fetch(\$id)",
            $this->build_fetch_suggestion(),
            'high'
        );
    }

    private function model_defines_portal_can_read(string $contents): bool
    {
        return (bool) preg_match('/function\s+portal_can_read\s*\(/', $contents);
    }

    private function extract_method_body(string $contents, string $method_name): ?string
    {
        $pattern = '/public\s+static\s+function\s+' . preg_quote($method_name, '/') . '\s*\([^)]*\)[^{]*\{/s';

        if (!preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start_pos = $matches[0][1] + strlen($matches[0][0]) - 1;
        $brace_count = 1;
        $pos = $start_pos + 1;
        $length = strlen($contents);

        while ($pos < $length && $brace_count > 0) {
            $char = $contents[$pos];
            if ($char === '{') {
                $brace_count++;
            } elseif ($char === '}') {
                $brace_count--;
            }
            $pos++;
        }

        return substr($contents, $start_pos, $pos - $start_pos);
    }

    private function build_can_read_suggestion(): string
    {
        $s = [];
        $s[] = "A portal-fetchable model must declare portal_can_read(): bool (fail-closed).";
        $s[] = "";
        $s[] = "The #[Auth] gate on portal_fetch() answers 'may this user use this surface';";
        $s[] = "portal_can_read() answers 'may they see THIS ROW'. Both are required.";
        $s[] = "";
        $s[] = "Recommended: use the trait and implement portal_can_read():";
        $s[] = "    use App\\RSpade\\Core\\Portal\\Portal_Authorizable;";
        $s[] = "    class My_Model extends Rsx_Site_Model_Abstract {";
        $s[] = "        use Portal_Authorizable;";
        $s[] = "        public function portal_can_read(): bool {";
        $s[] = "            return Portal_Permission::has_client_access((int) \$this->client_id);";
        $s[] = "        }";
        $s[] = "    }";

        return implode("\n", $s);
    }

    private function build_fetch_suggestion(): string
    {
        $s = [];
        $s[] = "A hand-rolled portal_fetch() must defer to portal_can_read().";
        $s[] = "";
        $s[] = "Prefer the Portal_Authorizable trait, which supplies a correct portal_fetch().";
        $s[] = "If you must define it yourself:";
        $s[] = "    #[Ajax_Endpoint_Model_Fetch]";
        $s[] = "    #[Auth('is_logged_in')]";
        $s[] = "    public static function portal_fetch(\$id) {";
        $s[] = "        \$row = static::find(\$id);";
        $s[] = "        return (\$row && \$row->portal_can_read()) ? \$row->toArray() : false;";
        $s[] = "    }";

        return implode("\n", $s);
    }
}
