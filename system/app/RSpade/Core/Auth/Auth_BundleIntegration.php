<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Bundle\BundleIntegration_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Auth gates integration: generates the JS Permission mirrors for #[Auth_Check].
 *
 * For each check marked on a realm's Permission class, the build emits a same-named
 * static on the realm's JS Permission class, so a check is spelled identically in
 * both languages and composite logic has exactly ONE implementation (the PHP body):
 *
 *     Permission::can_view_billing()   // PHP - executes the method
 *     Permission.can_view_billing()    // JS  - reads the render-time grant map
 *
 * Hand-written JS twins of check logic are forbidden - dual implementations drift.
 *
 * OUTPUT SHAPE. The emitted files carry NO class declaration: the classes
 * (Permission, Portal_Permission) are framework core JS that already exist, so the
 * mirrors are trailing-statement attachments onto them.
 *
 *     Permission.can_view_billing = function () {
 *         return window.rsxapp.auth ? window.rsxapp.auth.can_view_billing === 1 : false;
 *     };
 *
 * SHADOWING. An attachment is an ASSIGNMENT: it would overwrite a hand-written
 * static of the same name. So a check whose name already exists as a method on the
 * target JS class is SKIPPED, and the hand-written body wins ('is_logged_in' is the
 * built-in case - the JS twin reads window.rsxapp.is_auth, which agrees with the
 * grant map in both realms). The skip set is DERIVED from the manifest's JS class
 * metadata rather than hard-coded, so it can never drift from the classes.
 *
 * ORDERING (BundleCompiler). The attachments must evaluate AFTER the class
 * declarations they attach to. The generic stub path (_get_js_stubs) cannot carry
 * them: files with no class are bucketed as "non-class files" and hoisted to the
 * FRONT of the bundle, which would run the attachments in the classes' temporal dead
 * zone. BundleCompiler therefore appends these files explicitly, after the
 * dependency-ordered class files. It also folds their content into the app bundle's
 * cache key, since they are inputs no bundle file list contains.
 *
 * PHASE ORDER. This runs in manifest Phase 6, after the Phase 5 support modules -
 * so Auth_ManifestSupport has already written $manifest_data['data']['auth'] and the
 * check registry is complete. The generated paths are recorded back into that same
 * index under 'mirror_stubs'.
 *
 * See: php artisan rsx:man auth_gates
 */
class Auth_BundleIntegration extends BundleIntegration_Abstract
{
    /** Project-relative directory the mirror files are written to. */
    public const STUB_DIR = 'storage/rsx-build/js-auth-stubs';

    /**
     * realm => [JS class the mirrors attach to, output filename].
     */
    private const REALM_TARGETS = [
        Auth_ManifestSupport::REALM_STAFF => ['Permission', 'Permission_Auth_Mirror.js'],
        Auth_ManifestSupport::REALM_PORTAL => ['Portal_Permission', 'Portal_Permission_Auth_Mirror.js'],
    ];

    public static function get_name(): string
    {
        return 'auth';
    }

    /**
     * Auth gates are declared with PHP attributes and JS decorators - no file
     * extension of their own.
     */
    public static function get_file_extensions(): array
    {
        return [];
    }

    /**
     * The mirror files an app bundle must include, as absolute paths.
     *
     * Read from the manifest's auth index (written by generate_manifest_stubs), so
     * the compiler never has to guess filenames.
     *
     * @return array<int, string>
     */
    public static function get_mirror_stub_paths(): array
    {
        $manifest = Manifest::get_full_manifest();
        $relative_paths = $manifest['data']['auth']['mirror_stubs'] ?? [];

        $paths = [];

        foreach ($relative_paths as $relative_path) {
            $full_path = rsx_project_file_path($relative_path);

            if (!file_exists($full_path)) {
                throw new \RuntimeException(
                    "Bundle compile references a generated auth mirror that is missing from disk:\n" .
                    "  stub: {$relative_path}\n\n" .
                    "The manifest records this file but its Phase-6 output does not exist (the\n" .
                    "js-auth-stubs directory was pruned while the manifest cache stayed fresh).\n" .
                    "Compiling would silently omit every generated Permission check mirror.\n\n" .
                    'Remedy: php artisan rsx:manifest:build --force'
                );
            }

            $paths[] = $full_path;
        }

        return $paths;
    }

    /**
     * Emit one mirror file per realm that has checks, and record them in the index.
     */
    public static function generate_manifest_stubs(array &$manifest_data): void
    {
        $stub_dir = rsx_project_file_path(self::STUB_DIR);

        if (!is_dir($stub_dir)) {
            mkdir($stub_dir, 0755, true);
        }

        $generated_filenames = [];
        $generated_relative_paths = [];

        foreach (self::REALM_TARGETS as $realm => [$js_class, $filename]) {
            $checks = array_keys($manifest_data['data']['auth']['checks'][$realm] ?? []);
            sort($checks);

            $hand_written = static::_hand_written_statics($manifest_data, $js_class);

            $mirrored = array_values(array_diff($checks, $hand_written));
            $skipped = array_values(array_intersect($checks, $hand_written));

            if (empty($mirrored) && empty($skipped)) {
                // The realm defines no checks at all - emit nothing, and let the
                // orphan sweep below remove a file left by a previous build.
                continue;
            }

            $content = static::_generate_mirror_content($realm, $js_class, $mirrored, $skipped);

            $full_path = $stub_dir . '/' . $filename;

            // The desired CONTENT is the fingerprint: it changes when the check set
            // changes, when the skip set changes, and when this generator's template
            // changes. Comparing it directly is both cheaper and stricter than a
            // stored hash, and it survives the auth index being rebuilt every build.
            if (!file_exists($full_path) || file_get_contents($full_path) !== $content) {
                file_put_contents_safe($full_path, $content);
            }

            $generated_filenames[] = $filename;
            $generated_relative_paths[] = self::STUB_DIR . '/' . $filename;
        }

        // Clean up orphaned mirrors (a realm that lost its last check, or a rename).
        foreach (glob($stub_dir . '/*.js') as $existing) {
            if (!in_array(basename($existing), $generated_filenames, true)) {
                unlink($existing);
            }
        }

        // Register with the auth index the support module built in Phase 5. This is
        // the manifest record BundleCompiler reads; the files deliberately do NOT
        // enter data.files, because they declare no class and are not scannable
        // source - they are build output owned by this integration.
        $manifest_data['data']['auth']['mirror_stubs'] = $generated_relative_paths;
    }

    /**
     * Static method names already declared BY HAND on a JS class.
     *
     * Derived from the manifest's own JS metadata, so a new hand-written method
     * automatically starts winning over a same-named generated attachment with no
     * list to maintain here.
     *
     * @return array<int, string>
     */
    private static function _hand_written_statics(array $manifest_data, string $js_class): array
    {
        $file = $manifest_data['data']['js_classes'][$js_class] ?? null;

        if ($file === null) {
            shouldnt_happen(
                "Auth mirror generation could not find the JS class '{$js_class}' in the manifest. " .
                'It is framework core (app/RSpade/Core/Js) and must always be indexed.'
            );
        }

        $metadata = $manifest_data['data']['files'][$file] ?? [];

        return array_keys($metadata['public_static_methods'] ?? []);
    }

    /**
     * Render one realm's mirror file.
     *
     * @param array<int, string> $mirrored Check names getting an attachment
     * @param array<int, string> $skipped  Check names a hand-written method owns
     */
    private static function _generate_mirror_content(string $realm, string $js_class, array $mirrored, array $skipped): string
    {
        $content = "/**\n";
        $content .= " * Auto-generated auth check mirrors for the {$realm} realm ({$js_class})\n";
        $content .= " * DO NOT EDIT - This file is automatically regenerated\n";
        $content .= " *\n";
        $content .= " * One static per #[Auth_Check]-marked method on the realm's PHP Permission class,\n";
        $content .= " * so a check is spelled identically in both languages. Each reads the render-time\n";
        $content .= " * grants map (window.rsxapp.auth) - never a network call.\n";
        $content .= " *\n";
        $content .= " * See: php artisan rsx:man auth_gates\n";

        if (!empty($skipped)) {
            $content .= " *\n";
            $content .= " * Not mirrored ({$js_class} declares these by hand; the hand-written body wins):\n";
            foreach ($skipped as $check_name) {
                $content .= " *   {$check_name}\n";
            }
        }

        $content .= " */\n";

        foreach ($mirrored as $check_name) {
            $content .= "\n";
            $content .= "{$js_class}.{$check_name} = function () {\n";
            $content .= "    return window.rsxapp.auth ? window.rsxapp.auth.{$check_name} === 1 : false;\n";
            $content .= "};\n";
        }

        return $content;
    }
}
