<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\PHP;

use RuntimeException;

/**
 * Php_Fixer - Preprocesses PHP files before parsing
 *
 * Performs automatic fixes and enhancements to PHP source files during development:
 * - Auto-adds #[Relationship] attributes to model files
 * - Auto-updates namespaces based on file location
 * - Removes/rebuilds use statements for Rsx\ and App\RSpade\ classes
 * - Replaces FQCNs like \Rsx\Models\User_Model with simple names User_Model
 * - Removes leading backslashes from attributes: #[\Route] → #[Route]
 *
 * Only runs in non-production environments to avoid modifying deployed code.
 *
 * ======================================================================================
 * HOW TO ADD NEW RULES
 * ======================================================================================
 *
 * TO ADD A NEW FIX:
 * 1. Create a new private static method: __fix_your_feature($file_path, $content, $manifest)
 * 2. Add it to the fix() method's sequential application list (line ~123)
 * 3. Method signature: private static function __fix_*($file_path, $content, &$step_2_manifest_data): string
 * 4. Return the modified content (or original if no changes)
 *
 * EXAMPLE SKELETON:
 * ```php
 * private static function __fix_rsx_fqcn($file_path, string $content, array &$step_2_manifest_data): string
 * {
 *     // Only process files in rsx/ or app/RSpade/
 *     if (!str_starts_with($file_path, 'rsx/') && !str_starts_with($file_path, 'app/RSpade/')) {
 *         return $content;
 *     }
 *
 *     // Parse tokens for accurate replacement
 *     $tokens = token_get_all($content);
 *
 *     // Find patterns to fix
 *     // Build modifications array with positions
 *
 *     // Apply modifications (usually in reverse order to preserve positions)
 *
 *     return $modified_content;
 * }
 * ```
 *
 * IMPORTANT PATTERNS:
 * - Always work with tokens for PHP syntax awareness
 * - Build modifications array, then apply in reverse order (preserves positions)
 * - Use step_2_manifest_data to look up class information
 * - Return original content if no changes needed
 * - Never modify files in production environment (checked by caller)
 *
 * MANIFEST DATA AVAILABLE:
 * - static::$data['data']['files'] - All indexed files with metadata
 * - Files processed earlier in Phase 2 have complete metadata
 * - Current file NOT in manifest yet (being processed now)
 * - See method docblock for fix() for complete manifest structure details
 *
 * ======================================================================================
 */
class Php_Fixer
{
    /**
     * Floor for "the class index is complete enough to delete an import" (guard 2, B-68).
     *
     * The framework indexes ~750 PHP classes; a healthy build is nowhere near this number.
     * It is set low on purpose - the question is "is the index credible", not "is it the
     * size I expect", and a floor that tracked the real count would trip on every genuine
     * class removal.
     */
    private const MIN_FRAMEWORK_CLASSES_FOR_DELETION = 100;

    /**
     * Fix a PHP file by applying automatic improvements
     *
     * Called during Manifest Phase 2 (Parse Metadata) before token parsing extracts metadata.
     *
     * MANIFEST DATA STRUCTURE AT THIS POINT (Phase 2):
     *
     * $step_2_manifest_data = [
     *     'generated' => '2025-10-01 02:15:30',  // When manifest was generated
     *     'hash' => 'abc123...',                 // Overall manifest hash
     *     'data' => [
     *         'files' => [
     *             'rsx/app/demo/demo_controller.php' => [
     *                 'file' => 'rsx/app/demo/demo_controller.php',  // Relative path
     *                 'hash' => 'def456...',                         // File SHA1 hash
     *                 'mtime' => 1696118130,                         // Unix timestamp
     *                 'size' => 4523,                                // Bytes
     *                 'extension' => 'php',                          // File extension
     *                 'namespace' => 'Rsx\\App\\Demo',               // PHP namespace (if class file)
     *                 'class' => 'Demo_Controller',                  // Simple class name (if exists)
     *                 'fqcn' => 'Rsx\\App\\Demo\\Demo_Controller',   // Fully qualified class name
     *                 'extends' => 'Rsx_Controller_Abstract',        // Parent class simple name (if extends)
     *                 // Note: 'extends_fqcn' NOT available yet - added in Phase 4 via reflection
     *             ],
     *             // ... all other files processed so far in Phase 2, plus unchanged files from cache
     *         ],
     *         'autoloader_class_map' => [...],  // May be empty/incomplete during Phase 2
     *     ]
     * ]
     *
     * WHAT'S GUARANTEED AVAILABLE:
     * - Files processed earlier in Phase 2 loop have: file, hash, mtime, size, extension, namespace, class, fqcn, extends
     * - Files unchanged from previous manifest build have all their previous data
     * - Current file being fixed is NOT in the manifest yet (it's being processed now)
     *
     * WHAT'S NOT AVAILABLE YET:
     * - extends_fqcn (added in Phase 4 via reflection)
     * - attributes (added in Phase 4 via reflection)
     * - public_static_methods (added in Phase 4 via reflection)
     * - Complete autoloader_class_map (built after all Phase 2 parsing completes)
     *
     * USE CASES:
     * - Auto-fix namespaces based on file path
     * - Auto-add #[Relationship] attributes to model files
     * - Look up parent classes by simple name in manifest to find their FQCNs
     *
     * @param string $file_path Relative path from base_path()
     * @param array $step_2_manifest_data Manifest state during Phase 2 (passed by reference)
     * @return bool True if file was modified, false otherwise
     */
    public static function fix(string $file_path, array &$step_2_manifest_data): bool
    {
        // Never process ourselves to avoid infinite recursion or self-modification
        if (str_ends_with($file_path, 'Php_Fixer.php')) {
            return false;
        }

        // Skip non-PHP files based on extension
        if (!str_ends_with($file_path, '.php')) {
            return false;
        }

        // Skip blade.php files
        if (str_ends_with($file_path, '.blade.php')) {
            return false;
        }

        // Determine if this is a framework file (app/RSpade/) vs user file (rsx/)
        $is_rsx_file = str_starts_with($file_path, 'rsx/');
        $is_framework_file = str_starts_with($file_path, 'app/RSpade/');
        $is_framework_developer = config('rsx.code_quality.is_framework_developer', false);

        // Skip files not in rsx/ or app/RSpade/
        if (!$is_rsx_file && !$is_framework_file) {
            return false;
        }

        // Determine what fixes to apply based on mode and file location
        // - Framework developer mode: full fixes on all files
        // - Non-framework developer mode:
        //   - rsx/ files: full fixes
        //   - app/RSpade/ files: ONLY use statement fixes (for class override support)
        $use_statements_only = !$is_framework_developer && $is_framework_file;

        $absolute_path = base_path($file_path);
        $original_content = file_get_contents($absolute_path);

        // Skip empty files
        if (trim($original_content) === '') {
            return false;
        }

        // Quick check if file has a class - use token parsing
        $tokens = token_get_all($original_content);
        $has_class = false;
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_CLASS) {
                $has_class = true;
                break;
            }
        }

        // Skip files without classes
        if (!$has_class) {
            return false;
        }

        // Apply fixes sequentially
        // For framework files in non-developer mode, only fix use statements
        $modified_content = $original_content;

        if (!$use_statements_only) {
            $modified_content = self::__fix_namespace($file_path, $modified_content, $step_2_manifest_data);
        }

        $modified_content = self::__fix_use_statements($file_path, $modified_content, $step_2_manifest_data);

        if (!$use_statements_only) {
            $modified_content = self::__fix_model_relationships($file_path, $modified_content, $step_2_manifest_data);
            $modified_content = self::__fix_attribute_namespacing($file_path, $modified_content, $step_2_manifest_data);
        }

        // Save if modified
        if ($modified_content !== $original_content) {
            if (trim($modified_content) === '') {
                throw new RuntimeException("Php_Fixer produced empty output for file: {$file_path}. This should never happen.");
            }
            // Label the mutation-marker record for this write precisely (the generic
            // file_put_contents_safe hook consumes this one-shot hint).
            \App\RSpade\Core\Framework\Framework_Mutations::$next_mechanism = 'php_fixer';
            file_put_contents_safe($absolute_path, $modified_content);
            return true;
        }

        return false;
    }

    /**
     * Fix namespace declaration to match file path
     *
     * @param string $file_path Relative path from base_path()
     * @param string $content Current file content
     * @param array $step_2_manifest_data Manifest state during Phase 2
     * @return string Modified content
     */
    private static function __fix_namespace(string $file_path, string $content, array &$step_2_manifest_data): string
    {
        // Determine which directory this file is in
        $is_rsx_file = str_starts_with($file_path, 'rsx/');
        $is_rspade_file = str_starts_with($file_path, 'app/RSpade/');

        // Only process rsx/ or app/RSpade/ files
        if (!$is_rsx_file && !$is_rspade_file) {
            return $content;
        }

        // Calculate expected namespace from path
        $expected_namespace = self::__calculate_namespace_from_path($file_path);

        // Extract current namespace from file
        $tokens = token_get_all($content);
        $current_namespace = self::__extract_namespace_from_tokens($tokens);

        // If namespaces match, nothing to do
        if ($current_namespace === $expected_namespace) {
            return $content;
        }

        // Handle app/RSpade files - throw error, don't fix
        if ($is_rspade_file) {
            throw new RuntimeException(
                "File {$file_path} has invalid namespace '{$current_namespace}'. " .
                "Expected namespace: '{$expected_namespace}'. " .
                'Framework files in app/RSpade must have correct namespaces.'
            );
        }

        // Handle rsx/ files - auto-fix the namespace
        return self::__replace_namespace_in_content($content, $tokens, $expected_namespace);
    }

    /**
     * Calculate expected namespace from file path using Laravel conventions
     *
     * @param string $file_path Relative path from base_path()
     * @return string Expected namespace
     */
    private static function __calculate_namespace_from_path(string $file_path): string
    {
        // Remove .php extension
        $path_without_ext = substr($file_path, 0, -4);

        // Split into parts
        $parts = explode('/', $path_without_ext);

        // Handle rsx/ files
        if ($parts[0] === 'rsx') {
            array_shift($parts); // Remove 'rsx'
            array_pop($parts);   // Remove filename

            // Capitalize each part (PascalCase conversion)
            $namespace_parts = [];
            foreach ($parts as $part) {
                // Convert snake_case or kebab-case to PascalCase
                $words = explode(' ', str_replace(['_', '-'], ' ', $part));
                $capitalized = '';
                foreach ($words as $word) {
                    $capitalized .= ucfirst($word);
                }
                $namespace_parts[] = $capitalized;
            }

            $namespace = 'Rsx';
            if (!empty($namespace_parts)) {
                $namespace .= '\\' . implode('\\', $namespace_parts);
            }

            return $namespace;
        }

        // Handle app/RSpade files (and other app/* files)
        if ($parts[0] === 'app') {
            array_shift($parts); // Remove 'app'
            array_pop($parts);   // Remove filename

            // Capitalize each part
            $namespace_parts = [];
            foreach ($parts as $part) {
                $words = explode(' ', str_replace(['_', '-'], ' ', $part));
                $capitalized = '';
                foreach ($words as $word) {
                    $capitalized .= ucfirst($word);
                }
                $namespace_parts[] = $capitalized;
            }

            $namespace = 'App';
            if (!empty($namespace_parts)) {
                $namespace .= '\\' . implode('\\', $namespace_parts);
            }

            return $namespace;
        }

        // Should never reach here - __fix_namespace() only calls this for rsx/ or app/ files
        shouldnt_happen("__calculate_namespace_from_path() called with unexpected file path: {$file_path}");
    }

    /**
     * Extract namespace from tokens
     *
     * @param array $tokens Token array from token_get_all()
     * @return string|null Current namespace or null if none
     */
    private static function __extract_namespace_from_tokens(array $tokens): ?string
    {
        for ($i = 0; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';
                $i++; // Move past T_NAMESPACE

                while ($i < count($tokens) && $tokens[$i] !== ';') {
                    if (is_array($tokens[$i])) {
                        // Handle PHP 8's T_NAME_QUALIFIED token
                        if (defined('T_NAME_QUALIFIED') && $tokens[$i][0] === T_NAME_QUALIFIED) {
                            $namespace .= $tokens[$i][1];
                        }
                        // Handle PHP 8's T_NAME_FULLY_QUALIFIED token
                        elseif (defined('T_NAME_FULLY_QUALIFIED') && $tokens[$i][0] === T_NAME_FULLY_QUALIFIED) {
                            $namespace .= $tokens[$i][1];
                        }
                        // Handle older PHP versions
                        elseif ($tokens[$i][0] === T_STRING || $tokens[$i][0] === T_NS_SEPARATOR) {
                            $namespace .= $tokens[$i][1];
                        }
                    }
                    $i++;
                }

                return trim($namespace);
            }
        }

        return null;
    }

    /**
     * Replace or add namespace in file content
     *
     * @param string $content Original file content
     * @param array $tokens Token array from token_get_all()
     * @param string $new_namespace Namespace to set
     * @return string Modified content
     */
    private static function __replace_namespace_in_content(string $content, array $tokens, string $new_namespace): string
    {
        $output = '';
        $namespace_written = false;
        $namespace_found = false;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                // Handle namespace replacement
                if ($token[0] === T_NAMESPACE) {
                    $namespace_found = true;
                    $output .= "\nnamespace {$new_namespace};";
                    $namespace_written = true;

                    // Skip to semicolon
                    while ($i < count($tokens) && $tokens[$i] !== ';') {
                        $i++;
                    }
                    continue;
                }

                // If we hit a class and haven't written namespace yet, add it
                if ($token[0] === T_CLASS && !$namespace_written) {
                    $output .= "\nnamespace {$new_namespace};\n\n";
                    $namespace_written = true;
                }

                $output .= $token[1];
            } else {
                $output .= $token;
            }
        }

        return $output;
    }

    /**
     * Fix use statements - remove unnecessary ones, add missing ones
     *
     * THREE-STEP PROCESS:
     *
     * STEP 1: Remove all Rsx\ and App\RSpade\ use statements
     * - We'll rebuild these based on actual usage
     * - Protects vendor/Laravel use statements (never removes)
     *
     * STEP 2: Replace ALL Rsx\ FQCNs with simple names
     * - Converts: \Rsx\Models\User_Model::class → User_Model::class
     * - Works for ALL classes in manifest (even non-unique names)
     * - Relies on Step 3 to add disambiguating use statements
     *
     * STEP 3: Re-add use statements based on actual usage
     * - Scans for simple class name references (from Step 2 replacements)
     * - Looks up FQCNs in manifest
     * - Adds: use Rsx\Models\User_Model;
     * - Disambiguates non-unique class names automatically
     *
     * EXAMPLE TRANSFORMATION:
     * Before:
     *   return $this->belongsTo(\Rsx\Models\User_Model::class, 'team_lead_id');
     *
     * After:
     *   use Rsx\Models\User_Model;
     *   ...
     *   return $this->belongsTo(User_Model::class, 'team_lead_id');
     *
     * @param string $file_path Relative path from base_path()
     * @param string $content Current file content
     * @param array $step_2_manifest_data Manifest state during Phase 2
     * @return string Modified content
     */
    private static function __fix_use_statements(string $file_path, string $content, array &$step_2_manifest_data): string
    {
        // Only process files in rsx/ or app/RSpade/
        $is_rsx_file = str_starts_with($file_path, 'rsx/');
        $is_rspade_file = str_starts_with($file_path, 'app/RSpade/');

        if (!$is_rsx_file && !$is_rspade_file) {
            return $content;
        }

        // Parse tokens once for all operations
        $tokens = token_get_all($content);

        // Extract existing non-Rsx\ use statement simple names BEFORE removing Rsx\ statements
        $existing_non_rsx_simple_names = self::__extract_existing_non_rsx_use_simple_names($tokens, $file_path, $step_2_manifest_data);

        // Step 1: Process use statements - remove, keep, or redirect to correct FQCN
        // The manifest is the source of truth for where classes are defined
        $content = self::__remove_rsx_use_statements($content, $tokens, $file_path, $step_2_manifest_data);

        // Step 2: For both rsx/ and app/RSpade/ files - fix fully qualified class names
        // Replace \Rsx\Namespace\ClassName with just ClassName if the class exists uniquely
        $content = self::__replace_rsx_fqcn_with_simple_names($content, $step_2_manifest_data);

        //Step 3: Add use statements for Rsx\ classes
        // Re-parse tokens after content modification
        $tokens = token_get_all($content);

        // Find all simple class names used in the file
        $referenced_classes = self::__find_referenced_simple_classes($tokens);

        // Add extends/implements from manifest (more reliable than parsing)
        $file_metadata = $step_2_manifest_data['data']['files'][$file_path] ?? null;

        // var_dump($step_2_manifest_data['data']['files'][$file_path]);
        if (!$file_metadata) {
            shouldnt_happen("File metadata for $file_path in Manifest for step_2_manifest_data is not present");
        }

        if (!empty($file_metadata['extends'])) {
            $referenced_classes[] = $file_metadata['extends'];
        }
        if (!empty($file_metadata['implements']) && is_array($file_metadata['implements'])) {
            foreach ($file_metadata['implements'] as $interface) {
                $referenced_classes[] = $interface;
            }
        }

        // Deduplicate
        $referenced_classes = array_unique($referenced_classes);

        // Remove the current file's class name from referenced classes
        // (we don't need a use statement for the class being defined in this file)
        $current_class = $file_metadata['class'] ?? null;
        if ($current_class) {
            $referenced_classes = array_filter($referenced_classes, function ($class) use ($current_class) {
                return $class !== $current_class;
            });
        }

        // Find which of these classes exist in manifest
        $rsx_classes_to_import = self::__match_classes_files($referenced_classes, $step_2_manifest_data);

        // CRITICAL: Framework files in app/RSpade/ must NEVER import Rsx\ classes
        // In production, Rsx\ class paths are unpredictable (user-defined structure)
        // The autoloader will find them without explicit use statements
        if ($is_rspade_file) {
            $rsx_classes_to_import = array_filter($rsx_classes_to_import, function($fqcn) {
                return !str_starts_with($fqcn, 'Rsx\\');
            });
        }

        // Add use statements for found classes (excluding conflicts with existing non-Rsx\ use statements)
        if (!empty($rsx_classes_to_import)) {
            $content = self::__add_use_statements($content, $rsx_classes_to_import, $existing_non_rsx_simple_names);
        }

        // if ($file_path == 'app/RSpade/Core/Controller/Controller_BundleIntegration.php') {
        //     echo($content);
        //     die('cool');
        // }
        // if ($file_metadata['extends'] == 'Rsx_Bundle_Abstract') {
        //     echo($content);
        //     die('cool');
        // }

        return $content;
    }

    /**
     * Extract simple class names from existing use statements that won't be removed
     *
     * @param array $tokens Parsed tokens
     * @param string $file_path File being processed
     * @param array $step_2_manifest_data Manifest data for class lookups
     * @return array Simple class names already in use (e.g., ['Session', 'Request', 'Cache'])
     */
    private static function __extract_existing_non_rsx_use_simple_names(array $tokens, string $file_path, array &$step_2_manifest_data): array
    {
        $simple_names = [];
        $i = 0;

        while ($i < count($tokens)) {
            $token = $tokens[$i];

            // Stop at class declaration
            if (is_array($token) && $token[0] === T_CLASS) {
                break;
            }

            // Check for use statements
            if (is_array($token) && $token[0] === T_USE) {
                $i++; // Move past T_USE

                // Collect the use statement FQCN and check for 'as' keyword
                $use_fqcn = '';
                $has_as_alias = false;
                while ($i < count($tokens) && $tokens[$i] !== ';') {
                    if (is_array($tokens[$i])) {
                        // Check for 'as' keyword - stop collecting FQCN at this point
                        if ($tokens[$i][0] === T_AS) {
                            $has_as_alias = true;
                            // Skip to semicolon without collecting more
                            while ($i < count($tokens) && $tokens[$i] !== ';') {
                                $i++;
                            }
                            break;
                        }

                        if (defined('T_NAME_QUALIFIED') && $tokens[$i][0] === T_NAME_QUALIFIED) {
                            $use_fqcn .= $tokens[$i][1];
                        } elseif (defined('T_NAME_FULLY_QUALIFIED') && $tokens[$i][0] === T_NAME_FULLY_QUALIFIED) {
                            $use_fqcn .= $tokens[$i][1];
                        } elseif ($tokens[$i][0] === T_STRING || $tokens[$i][0] === T_NS_SEPARATOR) {
                            $use_fqcn .= $tokens[$i][1];
                        }
                    }
                    $i++;
                }

                $use_fqcn = trim($use_fqcn);

                // CRITICAL CHECK: Disallow 'as' aliases for Rsx\ or App\RSpade\ classes
                if ($has_as_alias && (str_starts_with($use_fqcn, 'Rsx\\') || str_starts_with($use_fqcn, 'App\\RSpade\\'))) {
                    throw new RuntimeException(
                        "FATAL: 'use' statement with 'as' alias detected in {$file_path}:\n" .
                        "    use {$use_fqcn} as ...\n\n" .
                        "RSX/RSpade classes CANNOT use 'as' aliases in use statements.\n\n" .
                        "Rationale:\n" .
                        "- Aliases make code less clear about which specific classes are involved\n" .
                        "- Aliases break RSX reflection-based code execution patterns\n" .
                        "- Aliases prevent framework introspection and auto-discovery\n\n" .
                        "Solution: Remove the 'as' keyword and use the class's actual name.\n" .
                        "If there's a naming conflict, the framework will handle it automatically."
                    );
                }

                // Check if this use statement will be kept (not removed/redirected)
                $action = self::__should_remove_use_statement($use_fqcn, $file_path, $step_2_manifest_data);

                if ($action === false) {
                    // Keeping as-is - extract simple name
                    $simple_name = substr(strrchr($use_fqcn, '\\'), 1) ?: $use_fqcn;
                    $simple_names[$simple_name] = true;
                } elseif (is_string($action)) {
                    // Redirecting to new FQCN - extract simple name from new FQCN
                    $simple_name = substr(strrchr($action, '\\'), 1) ?: $action;
                    $simple_names[$simple_name] = true;
                }
                // If $action === true, it's being removed, don't add to simple_names
            }

            $i++;
        }

        return array_keys($simple_names);
    }

    /**
     * Determine what action to take on a use statement
     *
     * Returns one of:
     * - false: Keep as-is (vendor/Laravel classes, or already correct)
     * - true: Remove completely (framework dev mode for Rsx\ classes, or attribute stubs)
     * - string: Update to this FQCN (use statement points to wrong FQCN for this class)
     *
     * The manifest is the source of truth. If a class exists in the manifest,
     * the use statement should point to the manifest's FQCN for that class.
     *
     * @param string $use_fqcn Fully qualified class name from use statement
     * @param string $file_path File being processed
     * @param array $step_2_manifest_data Manifest data for class lookups
     * @return bool|string False=keep, true=remove, string=update to new FQCN
     */
    /**
     * Is the class index healthy enough to justify DELETING an import?
     *
     * GUARD 2 (backlog B-68). A deletion is a claim that a class no longer exists, and the
     * only evidence for it is "the manifest cannot see it". That evidence is worthless
     * while the manifest itself is incomplete - and a partial index is a NORMAL state, not
     * an exotic one: any manifest-FATAL rule (SESSION-ID-01, POLY-01, SEALED-01,
     * PHP-PARENT-CHAIN-01, the auth-gate validator) aborts a build mid-index, and the next
     * rebuild then runs against what survived.
     *
     * That is exactly how this fired twice - 2026-08-05 and again 2026-08-09, both times
     * stripping `use App\RSpade\Core\Portal\Portal_Authorizable;` out of four app models.
     * The damage compounds: the missing trait then fatals EVERY later build, which is the
     * same condition that caused the deletion, so the tree cannot rebuild its way out. Both
     * incidents were recovered by hand with `git restore`.
     *
     * A FLOOR, not a null test. The index is never literally empty here - _run_php_fixer()
     * iterates it to choose the files to fix, so an empty one would fix nothing at all. The
     * real failure is a PARTIAL index: app entries present, framework class metadata
     * missing. So the question is whether the framework side of the index looks credible.
     *
     * Deliberately one-directional: this gates DELETIONS only. Rewrites and additions still
     * run, because both are driven by positive evidence (a class that IS in the index).
     */
    private static function __class_index_is_healthy(array &$step_2_manifest_data): bool
    {
        static $healthy = null;

        if ($healthy !== null) {
            return $healthy;
        }

        $framework_classes = 0;
        foreach ($step_2_manifest_data['data']['files'] ?? [] as $manifest_file => $metadata) {
            if (str_starts_with($manifest_file, 'app/RSpade/') && isset($metadata['class'])) {
                $framework_classes++;

                if ($framework_classes >= self::MIN_FRAMEWORK_CLASSES_FOR_DELETION) {
                    $healthy = true;

                    return $healthy;
                }
            }
        }

        $healthy = false;

        // Loud, once. A silent guard would hide the poisoned build that tripped it.
        error_log(
            '[Php_Fixer] Class index looks incomplete (' . $framework_classes . ' framework classes indexed, '
            . 'expected at least ' . self::MIN_FRAMEWORK_CLASSES_FOR_DELETION . '). '
            . 'Skipping ALL use-statement deletions for this pass so a degraded manifest cannot '
            . 'strip load-bearing imports (backlog B-68). Rewrites and additions still ran.'
        );

        return $healthy;
    }

    /**
     * Does a file defining this class exist under a framework path the manifest never scans?
     *
     * GUARD 1 (backlog B-68, the F-021 half). `config('rsx.manifest.scan_directories')` lists
     * nine paths; everything else under app/RSpade/ - Commands, Database, Http, Ide,
     * SchemaQuality - is PERMANENTLY invisible to the manifest by design. An import of a
     * class living there is therefore unresolvable on a perfectly healthy index, and was
     * being deleted every single build.
     *
     * Deletion must rest on positive proof that a class is gone, never on absence of
     * evidence. This is that proof, and it is cheap: one glob per unresolved name.
     */
    private static function __class_file_exists_outside_the_index(string $simple_name): bool
    {
        if ($simple_name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $simple_name)) {
            return false;
        }

        $scanned = config('rsx.manifest.scan_directories', []);
        $framework_root = base_path('app/RSpade');

        if (!is_dir($framework_root)) {
            return false;
        }

        foreach (scandir($framework_root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relative = 'app/RSpade/' . $entry;
            if (!is_dir($framework_root . '/' . $entry) || in_array($relative, $scanned, true)) {
                continue;
            }

            // Unscanned zone: does it define the class we were about to declare dead?
            $matches = self::__find_php_file_named($framework_root . '/' . $entry, $simple_name . '.php');
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /** Recursive filename lookup, first hit wins. */
    private static function __find_php_file_named(string $directory, string $filename): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $filename) {
                return true;
            }
        }

        return false;
    }

    private static function __should_remove_use_statement(string $use_fqcn, string $file_path, array &$step_2_manifest_data): bool|string
    {
        // Get excluded directories from config
        $excluded_dirs = config('rsx.manifest.excluded_dirs', ['vendor', 'node_modules', 'storage', '.git', 'public', 'resource', 'archived']);

        // Extract simple class name and namespace
        $last_backslash = strrpos($use_fqcn, '\\');
        $simple_name = $last_backslash !== false ? substr($use_fqcn, $last_backslash + 1) : $use_fqcn;

        if ($last_backslash !== false) {
            $namespace_part = substr($use_fqcn, 0, $last_backslash);

            // Check if namespace contains any excluded directory keyword (case insensitive)
            // These are protected namespaces (vendor, Laravel, etc.) - never modify
            foreach ($excluded_dirs as $excluded_dir) {
                if (stripos($namespace_part, $excluded_dir) !== false) {
                    return false; // Protected - don't touch
                }
            }
        }

        // Remove "Route" explicitly - refers to Laravel Route:: helper which conflicts with our #[Route] attribute
        if ($use_fqcn == 'Route' && (str_starts_with($file_path, 'rsx/') || str_starts_with($file_path, 'app/RSpade/'))) {
            return true;
        }

        // HACKY CHECK: If this is a simple class name (no namespace), check if it's defined in ._rsx_helper.php
        // This catches attribute stub classes that IDEs auto-import incorrectly
        if (strpos($use_fqcn, '\\') === false) {
            $helper_file = dirname(base_path()) . '/._rsx_helper.php';
            if (file_exists($helper_file)) {
                $helper_content = file_get_contents($helper_file);
                // Look for class declaration pattern: "    class ClassName {\n"
                $pattern = '/^\s+class\s+' . preg_quote($use_fqcn, '/') . '\s+\{/m';
                if (preg_match($pattern, $helper_content)) {
                    return true; // Remove - it's an attribute stub from ._rsx_helper.php
                }
            }
        }

        // Look up the correct FQCN for this class in the manifest
        $correct_fqcn = self::__find_class_fqcn_in_manifest($simple_name, $step_2_manifest_data);

        if ($correct_fqcn !== null) {
            // Class exists in manifest - check if use statement is correct
            if ($use_fqcn === $correct_fqcn) {
                // Already pointing to correct FQCN
                $is_framework_developer = config('rsx.code_quality.is_framework_developer', false);

                // In framework developer mode, remove Rsx\ use statements (autoloader finds them)
                if ($is_framework_developer && str_starts_with($use_fqcn, 'Rsx\\')) {
                    return true;
                }

                return false; // Keep as-is
            } else {
                // Use statement points to wrong FQCN
                $is_framework_developer = config('rsx.code_quality.is_framework_developer', false);

                if ($is_framework_developer && str_starts_with($correct_fqcn, 'Rsx\\')) {
                    // Framework developer mode: remove, autoloader will find Rsx\ classes
                    return true;
                }

                // Update to correct FQCN
                return $correct_fqcn;
            }
        }

        // A Rsx\ or App\RSpade\ import the manifest cannot resolve. Historically this was an
        // unconditional delete, on the reasoning that the re-add pass would restore anything
        // still referenced. That reasoning does not hold, for two independent reasons, and
        // the two guards below are the answers to them (backlog B-68):
        //
        //   - The re-add scanner does not see every kind of reference (a trait `use` inside
        //     a class body is the one that bit us), so a wrongly-deleted import may never
        //     come back at all.
        //   - Even for references it DOES see, re-adding resolves through the same manifest
        //     that just failed to resolve the deletion - so the pass that deletes can never
        //     be the pass that restores.
        if (str_starts_with($use_fqcn, 'Rsx\\') || str_starts_with($use_fqcn, 'App\\RSpade\\')) {
            // GUARD 2: the index is not in a fit state to declare anything missing.
            if (!self::__class_index_is_healthy($step_2_manifest_data)) {
                return false;
            }

            // GUARD 1: it lives in a framework zone the manifest deliberately never scans,
            // so it was never going to resolve, healthy index or not.
            if (self::__class_file_exists_outside_the_index($simple_name)) {
                return false;
            }

            return true;
        }

        return false; // Keep all other use statements
    }

    /**
     * Find the correct FQCN for a PHP class by simple name in the manifest
     *
     * Searches the entire manifest for the class. Returns the FQCN from the manifest,
     * which is the authoritative source for where classes are defined.
     *
     * @param string $simple_name Simple class name (e.g., "User_Model")
     * @param array $step_2_manifest_data Manifest data
     * @return string|null FQCN if found in manifest, null otherwise
     */
    private static function __find_class_fqcn_in_manifest(string $simple_name, array &$step_2_manifest_data): ?string
    {
        foreach ($step_2_manifest_data['data']['files'] ?? [] as $manifest_file => $metadata) {
            // Only check PHP files (not JS classes with same name)
            if (!str_ends_with($manifest_file, '.php')) {
                continue;
            }

            // Only check files in rsx/ or app/RSpade/
            if (!str_starts_with($manifest_file, 'rsx/') && !str_starts_with($manifest_file, 'app/RSpade/')) {
                continue;
            }

            // Check if this file defines the class we're looking for
            if (isset($metadata['class']) && $metadata['class'] === $simple_name) {
                // Generate FQCN from file path
                return self::__calculate_namespace_from_path($manifest_file) . '\\' . $simple_name;
            }
        }

        return null;
    }

    /**
     * Process use statements - remove, keep, or redirect as needed
     *
     * @param string $content File content
     * @param array $tokens Parsed tokens
     * @param string $file_path File being processed
     * @param array $step_2_manifest_data Manifest data for class lookups
     * @return string Content with use statements processed
     */
    private static function __remove_rsx_use_statements(string $content, array $tokens, string $file_path, array &$step_2_manifest_data): string
    {
        $output = '';
        $i = 0;

        while ($i < count($tokens)) {
            $token = $tokens[$i];

            // Stop processing when we hit the class declaration
            if (is_array($token) && $token[0] === T_CLASS) {
                // Add the rest of the file unchanged
                while ($i < count($tokens)) {
                    if (is_array($tokens[$i])) {
                        $output .= $tokens[$i][1];
                    } else {
                        $output .= $tokens[$i];
                    }
                    $i++;
                }
                break;
            }

            // Check for use statements
            if (is_array($token) && $token[0] === T_USE) {
                $use_start = $i;
                $use_statement = '';
                $i++; // Move past T_USE

                // Collect the use statement to check if it's Rsx\
                while ($i < count($tokens) && $tokens[$i] !== ';') {
                    if (is_array($tokens[$i])) {
                        if (defined('T_NAME_QUALIFIED') && $tokens[$i][0] === T_NAME_QUALIFIED) {
                            $use_statement .= $tokens[$i][1];
                        } elseif (defined('T_NAME_FULLY_QUALIFIED') && $tokens[$i][0] === T_NAME_FULLY_QUALIFIED) {
                            $use_statement .= $tokens[$i][1];
                        } elseif ($tokens[$i][0] === T_STRING || $tokens[$i][0] === T_NS_SEPARATOR) {
                            $use_statement .= $tokens[$i][1];
                        }
                    }
                    $i++;
                }

                $use_statement = trim($use_statement);

                // Check what action to take on this use statement
                $action = self::__should_remove_use_statement($use_statement, $file_path, $step_2_manifest_data);

                if ($action === false) {
                    // Keep it - go back and add the original use statement
                    for ($j = $use_start; $j <= $i; $j++) {
                        if ($j < count($tokens)) {
                            if (is_array($tokens[$j])) {
                                $output .= $tokens[$j][1];
                            } else {
                                $output .= $tokens[$j];
                            }
                        }
                    }
                } elseif (is_string($action)) {
                    // Redirect - output a new use statement with the correct FQCN
                    $output .= "use {$action};";
                }
                // If $action === true, skip it entirely (don't add to output)
            } else {
                // Add token to output
                if (is_array($token)) {
                    $output .= $token[1];
                } else {
                    $output .= $token;
                }
            }

            $i++;
        }

        return $output;
    }

    /**
     * Replace fully qualified Rsx class names with simple names
     *
     * This function finds \Rsx\Namespace\ClassName patterns and replaces them
     * with just ClassName for ALL Rsx\ classes in manifest (even non-unique names).
     *
     * Step 3 of __fix_use_statements() will add the appropriate use statement,
     * which disambiguates non-unique class names.
     *
     * @param string $content File content
     * @param array $step_2_manifest_data Manifest data
     * @return string Content with FQCNs replaced
     */
    private static function __replace_rsx_fqcn_with_simple_names(string $content, array &$step_2_manifest_data): string
    {
        // Build a set of all valid Rsx\ class names in manifest
        $valid_rsx_classes = [];
        foreach ($step_2_manifest_data['data']['files'] ?? [] as $manifest_file => $metadata) {
            if (isset($metadata['class']) && !empty($metadata['class'])) {
                // Check if this file is an Rsx\ class (in rsx/ directory)
                if (str_starts_with($manifest_file, 'rsx/')) {
                    $simple_name = $metadata['class'];
                    $valid_rsx_classes[$simple_name] = true;
                }
            }
        }

        // Keep replacing until no more changes (handles cascading replacements)
        $max_iterations = 100; // Prevent infinite loops
        $iteration = 0;

        while ($iteration < $max_iterations) {
            $iteration++;
            $original_content = $content;

            // Parse tokens fresh each iteration
            $tokens = token_get_all($content);
            $modifications = [];

            for ($i = 0; $i < count($tokens); $i++) {
                $fqcn = null;
                $fqcn_start = null;
                $fqcn_end = null;

                // PHP 8+ uses T_NAME_FULLY_QUALIFIED for complete FQCN like \Rsx\Models\User_Model
                if (is_array($tokens[$i]) && defined('T_NAME_FULLY_QUALIFIED') && $tokens[$i][0] === T_NAME_FULLY_QUALIFIED) {
                    $fqcn = $tokens[$i][1];
                    $fqcn_start = $i;
                    $fqcn_end = $i + 1;
                }
                // Fallback* for older PHP or partial namespaces
                elseif ($tokens[$i] === '\\' || (is_array($tokens[$i]) && $tokens[$i][0] === T_NS_SEPARATOR)) {
                    // Check if this starts \Rsx\
                    $fqcn_start = $i;
                    $fqcn = '\\';
                    $j = $i + 1;

                    // Collect the full class name
                    while ($j < count($tokens)) {
                        if (is_array($tokens[$j])) {
                            if (defined('T_NAME_QUALIFIED') && $tokens[$j][0] === T_NAME_QUALIFIED) {
                                $fqcn .= $tokens[$j][1];
                                $j++;
                            } elseif ($tokens[$j][0] === T_STRING) {
                                $fqcn .= $tokens[$j][1];
                                $j++;
                            } elseif ($tokens[$j][0] === T_NS_SEPARATOR) {
                                $fqcn .= $tokens[$j][1];
                                $j++;
                            } else {
                                break;
                            }
                        } else {
                            break;
                        }
                    }
                    $fqcn_end = $j;
                }

                // Process FQCN if we found one
                if ($fqcn && str_starts_with($fqcn, '\\Rsx\\')) {
                    // Extract simple class name (last part after final \)
                    $parts = explode('\\', trim($fqcn, '\\'));
                    $simple_name = end($parts);

                    // Replace if this class exists in manifest (even if name is not unique)
                    // Step 3 will add the correct use statement to disambiguate
                    if (isset($valid_rsx_classes[$simple_name])) {
                        // Calculate the byte positions for replacement
                        $start_pos = 0;
                        for ($k = 0; $k < $fqcn_start; $k++) {
                            if (is_array($tokens[$k])) {
                                $start_pos += strlen($tokens[$k][1]);
                            } else {
                                $start_pos += strlen($tokens[$k]);
                            }
                        }

                        $length = 0;
                        for ($k = $fqcn_start; $k < $fqcn_end; $k++) {
                            if (is_array($tokens[$k])) {
                                $length += strlen($tokens[$k][1]);
                            } else {
                                $length += strlen($tokens[$k]);
                            }
                        }

                        $modifications[] = [
                            'start' => $start_pos,
                            'length' => $length,
                            'replacement' => $simple_name,
                        ];
                    }
                }
            }

            // Apply modifications in reverse order to preserve positions
            usort($modifications, function ($a, $b) {
                return $b['start'] - $a['start'];
            });

            foreach ($modifications as $mod) {
                $content = substr_replace($content, $mod['replacement'], $mod['start'], $mod['length']);
            }

            // If no changes were made, we're done
            if ($content === $original_content) {
                break;
            }
        }

        return $content;
    }

    /**
     * Find all simple class names referenced in the file
     *
     * This looks for class usage patterns like:
     * - new ClassName()
     * - ClassName::method()
     * - extends ClassName
     * - implements ClassName
     * - instanceof ClassName
     * - catch (ClassName $e)
     * - function foo(ClassName $param)
     * - function foo(): ClassName
     *
     * @param array $tokens Parsed tokens
     * @return array List of simple class names found
     */
    private static function __find_referenced_simple_classes(array $tokens): array
    {
        $classes = [];

        // Trait uses and typed properties are found in a separate pass because both need
        // to know whether we are inside a CLASS BODY, which the arms below do not track.
        // Their absence is what made a wrongly-deleted trait import unrecoverable: the
        // reference existed, in the one shape nothing here could see (backlog B-68).
        foreach (self::__find_class_body_references($tokens) as $class_name) {
            $classes[] = $class_name;
        }

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                // Check for extends/implements
                if ($token[0] === T_EXTENDS || $token[0] === T_IMPLEMENTS) {
                    $i++;
                    while ($i < count($tokens) && $tokens[$i] !== '{' && $tokens[$i] !== ',' && $tokens[$i] !== ';') {
                        if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                            $classes[] = $tokens[$i][1];
                        }
                        $i++;
                    }
                }
                // Check for static calls (ClassName::method)
                elseif ($token[0] === T_STRING && $i + 1 < count($tokens)) {
                    $next_significant = $i + 1;
                    // Skip whitespace
                    while ($next_significant < count($tokens) &&
                           is_array($tokens[$next_significant]) &&
                           $tokens[$next_significant][0] === T_WHITESPACE) {
                        $next_significant++;
                    }

                    if ($next_significant < count($tokens) &&
                        is_array($tokens[$next_significant]) &&
                        $tokens[$next_significant][0] === T_DOUBLE_COLON) {
                        $class_name = $token[1];
                        // Skip self, parent, static
                        if (!in_array($class_name, ['self', 'parent', 'static'])) {
                            $classes[] = $class_name;
                        }
                    }
                }
                // Check for new ClassName
                elseif ($token[0] === T_NEW) {
                    $i++;
                    while ($i < count($tokens) && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;
                    }
                    if ($i < count($tokens) && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                        $classes[] = $tokens[$i][1];
                    }
                }
                // Check for instanceof
                elseif ($token[0] === T_INSTANCEOF) {
                    $i++;
                    while ($i < count($tokens) && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;
                    }
                    if ($i < count($tokens) && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                        $classes[] = $tokens[$i][1];
                    }
                }
                // Check for catch blocks
                elseif ($token[0] === T_CATCH) {
                    $i++;
                    while ($i < count($tokens) && $tokens[$i] !== '(') {
                        $i++;
                    }
                    $i++; // Skip (
                    while ($i < count($tokens) && $tokens[$i] !== ')') {
                        if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                            $classes[] = $tokens[$i][1];
                        }
                        $i++;
                    }
                }
                // Check for type hints in function parameters and return types
                elseif ($token[0] === T_FUNCTION) {
                    $i++;
                    // Skip to parameters
                    while ($i < count($tokens) && $tokens[$i] !== '(') {
                        $i++;
                    }
                    // Process parameters
                    while ($i < count($tokens) && $tokens[$i] !== ')') {
                        if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                            // Check if it's followed by a variable (making it a type hint)
                            $j = $i + 1;
                            while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                                $j++;
                            }
                            if ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                                $classes[] = $tokens[$i][1];
                            }
                        }
                        $i++;
                    }
                    // Check for return type
                    if ($i < count($tokens) && $tokens[$i] === ')') {
                        $i++;
                        while ($i < count($tokens) && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                            $i++;
                        }
                        if ($i < count($tokens) && $tokens[$i] === ':') {
                            $i++;
                            while ($i < count($tokens) && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                                $i++;
                            }
                            if ($i < count($tokens)) {
                                // Handle nullable types
                                if ($tokens[$i] === '?') {
                                    $i++;
                                    while ($i < count($tokens) && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                                        $i++;
                                    }
                                }
                                if ($i < count($tokens) && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                                    $classes[] = $tokens[$i][1];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Remove duplicates and filter out PHP built-in types
        $built_in_types = ['string', 'int', 'float', 'bool', 'array', 'void', 'mixed', 'object', 'callable', 'iterable', 'null', 'true', 'false', 'never'];
        $classes = array_unique($classes);

        return array_filter($classes, function ($class) use ($built_in_types) {
            return !in_array(strtolower($class), $built_in_types);
        });
    }

    /**
     * Class references that only exist INSIDE a class body, and that the token arms in
     * __find_referenced_simple_classes() therefore cannot see (backlog B-68):
     *
     *   1. TRAIT USES - `use Portal_Authorizable;` at class-body depth. This is the exact
     *      shape that made the B-68 incidents unrecoverable: the four app models really did
     *      reference the trait, in the one way the scanner was blind to, so the deleted
     *      import was never re-added and every later build fataled on the missing trait.
     *
     *   2. TYPED PROPERTIES - `protected Portal_User_Model $user;`. The existing type-hint
     *      arm only runs after T_FUNCTION and only inside the parameter list, so a property
     *      type was never a reference either. Same class of bug, found while fixing the first.
     *
     * Disambiguation matters more than usual here, because T_USE has three meanings. Only
     * one of them is a trait use:
     *
     *   - depth 0            -> a namespace import (the thing this whole pass MANAGES)
     *   - followed by `(`    -> a closure's `use ($captured)` variable list
     *   - inside a class body-> a trait use, which is what we want
     *
     * @param array $tokens token_get_all() output
     * @return array<int, string> simple class names
     */
    private static function __find_class_body_references(array $tokens): array
    {
        $classes = [];
        $count = count($tokens);
        $depth = 0;
        $class_body_depth = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                if ($class_body_depth !== null && $depth === $class_body_depth) {
                    $class_body_depth = null;
                }
                $depth--;
                continue;
            }

            if (!is_array($token)) {
                continue;
            }

            // Entering a class/trait/interface/enum body: its opening brace is at depth+1.
            if (in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE], true)
                || (defined('T_ENUM') && $token[0] === T_ENUM)) {
                // Skip anonymous-class and ::class - neither opens a body we care about.
                $next = self::__next_significant($tokens, $i + 1);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $class_body_depth = $depth + 1;
                }
                continue;
            }

            if ($class_body_depth === null || $depth !== $class_body_depth) {
                continue;
            }

            // ---- 1. Trait uses ------------------------------------------------------
            if ($token[0] === T_USE) {
                $j = self::__next_significant($tokens, $i + 1);

                // `use (` is a closure capture list, never a trait.
                if ($j === null || $tokens[$j] === '(') {
                    continue;
                }

                // `use A, B;` and `use A { ... }` - collect names until the statement ends.
                while ($j !== null && $j < $count) {
                    $current = $tokens[$j];

                    if ($current === ';' || $current === '{') {
                        break;
                    }
                    if (is_array($current) && $current[0] === T_STRING) {
                        $classes[] = $current[1];
                    }
                    // A qualified name (Foo\Bar) is already resolvable - take its last segment.
                    if (is_array($current)
                        && in_array($current[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $parts = explode('\\', $current[1]);
                        $classes[] = end($parts);
                    }

                    $j = self::__next_significant($tokens, $j + 1);
                }

                continue;
            }

            // ---- 2. Typed properties ------------------------------------------------
            // A property declaration starts with a modifier; the type (if any) sits between
            // that and the variable. Nullable and readonly forms included.
            if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC], true)
                || (defined('T_READONLY') && $token[0] === T_READONLY)) {
                $j = self::__next_significant($tokens, $i + 1);
                $type_name = null;

                while ($j !== null && $j < $count) {
                    $current = $tokens[$j];

                    // Still in the modifier run, or a nullable/union marker - keep looking.
                    if ($current === '?' || $current === '|'
                        || (is_array($current) && in_array($current[0], [
                            T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC,
                        ], true))
                        || (is_array($current) && defined('T_READONLY') && $current[0] === T_READONLY)) {
                        $j = self::__next_significant($tokens, $j + 1);
                        continue;
                    }

                    if (is_array($current) && $current[0] === T_STRING) {
                        $type_name = $current[1];
                        $j = self::__next_significant($tokens, $j + 1);
                        continue;
                    }

                    // A variable right after a type makes it a typed PROPERTY. Anything
                    // else (T_FUNCTION, T_CONST, ...) means this was not one.
                    if (is_array($current) && $current[0] === T_VARIABLE && $type_name !== null) {
                        $classes[] = $type_name;
                    }

                    break;
                }
            }
        }

        return $classes;
    }

    /** Index of the next non-whitespace, non-comment token at or after $start. */
    private static function __next_significant(array $tokens, int $start): ?int
    {
        for ($i = $start; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * Match simple class names to files in rsx/ or app/RSpade/ directories
     *
     * @param array $class_names List of simple class names to find
     * @param array $step_2_manifest_data Manifest data
     * @return array Associative array of class_name => fqcn for classes found
     */
    private static function __match_classes_files(array $class_names, array &$step_2_manifest_data): array
    {
        $matched_classes = [];

        foreach ($class_names as $simple_name) {
            foreach ($step_2_manifest_data['data']['files'] ?? [] as $file_path => $metadata) {
                // Only check PHP files in rsx/ or app/RSpade/ with classes
                if ((str_starts_with($file_path, 'rsx/') || str_starts_with($file_path, 'app/RSpade/')) &&
                    str_ends_with($file_path, '.php') &&
                    isset($metadata['class']) &&
                    $metadata['class'] === $simple_name) {
                    // Generate FQCN from file path (not from manifest)
                    $fqcn = self::__calculate_namespace_from_path($file_path) . '\\' . $simple_name;
                    $matched_classes[$simple_name] = $fqcn;
                    break; // Found it, move to next class
                }
            }
        }

        // if (in_array('Rsx_Bundle_Abstract', $class_names)) {
        //     var_dump($matched_classes);
        //     die('Yeah');
        // }

        return $matched_classes;
    }

    /**
     * Add use statements to the file content
     *
     * Adds use statements right after the namespace declaration
     *
     * @param string $content File content
     * @param array $classes_to_import Associative array of class_name => fqcn
     * @return string Content with use statements added
     */
    private static function __add_use_statements(string $content, array $classes_to_import, array $exclude_simple_names = []): string
    {
        // Filter out classes whose simple name conflicts with existing use statements
        $filtered_classes = [];
        foreach ($classes_to_import as $simple_name => $fqcn) {
            if (!in_array($simple_name, $exclude_simple_names)) {
                $filtered_classes[$simple_name] = $fqcn;
            }
        }

        // Parse tokens to extract existing preserved use statements
        $tokens = token_get_all($content);
        $preserved_use_statements = [];

        $i = 0;
        while ($i < count($tokens)) {
            $token = $tokens[$i];

            // Stop at class declaration
            if (is_array($token) && $token[0] === T_CLASS) {
                break;
            }

            // Find use statements
            if (is_array($token) && $token[0] === T_USE) {
                $i++; // Move past T_USE
                $use_statement = '';

                // Collect the use statement
                while ($i < count($tokens) && $tokens[$i] !== ';') {
                    if (is_array($tokens[$i])) {
                        if (defined('T_NAME_QUALIFIED') && $tokens[$i][0] === T_NAME_QUALIFIED) {
                            $use_statement .= $tokens[$i][1];
                        } elseif (defined('T_NAME_FULLY_QUALIFIED') && $tokens[$i][0] === T_NAME_FULLY_QUALIFIED) {
                            $use_statement .= $tokens[$i][1];
                        } elseif ($tokens[$i][0] === T_STRING || $tokens[$i][0] === T_NS_SEPARATOR) {
                            $use_statement .= $tokens[$i][1];
                        }
                    }
                    $i++;
                }

                $use_statement = trim($use_statement);
                if ($use_statement) {
                    $preserved_use_statements[] = $use_statement;
                }
            }

            $i++;
        }

        // Combine preserved and auto-generated use statements (deduplicate)
        $all_use_statements = array_unique(array_merge($preserved_use_statements, array_values($filtered_classes)));

        if (empty($all_use_statements)) {
            return $content;
        }

        // Sort: everything else, then App\RSpade\, then Rsx\ (each sorted alphabetically)
        $other_classes = [];
        $rspade_classes = [];
        $rsx_classes = [];

        foreach ($all_use_statements as $fqcn) {
            if (str_starts_with($fqcn, 'App\\RSpade\\')) {
                $rspade_classes[] = $fqcn;
            } elseif (str_starts_with($fqcn, 'Rsx\\')) {
                $rsx_classes[] = $fqcn;
            } else {
                $other_classes[] = $fqcn;
            }
        }

        sort($other_classes);
        sort($rspade_classes);
        sort($rsx_classes);

        // Build use statements: others, then App\RSpade, then Rsx
        $use_statements = '';
        foreach ($other_classes as $fqcn) {
            $use_statements .= "use {$fqcn};\n";
        }
        foreach ($rspade_classes as $fqcn) {
            $use_statements .= "use {$fqcn};\n";
        }
        foreach ($rsx_classes as $fqcn) {
            $use_statements .= "use {$fqcn};\n";
        }

        // Parse tokens to rebuild file: namespace, then all use statements, then rest
        $tokens = token_get_all($content);
        $output = '';
        $namespace_found = false;
        $use_statements_added = false;
        $class_found = false;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                // Track when we enter class definition
                if ($token[0] === T_CLASS) {
                    $class_found = true;
                }

                // Skip import use statements (only BEFORE class definition)
                if ($namespace_found && !$class_found && $token[0] === T_USE) {
                    // This is an import use statement - skip it entirely
                    while ($i < count($tokens) && $tokens[$i] !== ';') {
                        $i++;
                    }
                    if ($i < count($tokens) && $tokens[$i] === ';') {
                        $i++;
                    }
                    // Skip trailing whitespace
                    while ($i < count($tokens) && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;
                    }
                    $i--; // Back up since for loop will increment
                } elseif ($token[0] === T_NAMESPACE) {
                    $namespace_found = true;
                    // Add namespace
                    $output .= $token[1];
                    $i++;

                    // Add everything up to semicolon
                    while ($i < count($tokens) && $tokens[$i] !== ';') {
                        if (is_array($tokens[$i])) {
                            $output .= $tokens[$i][1];
                        } else {
                            $output .= $tokens[$i];
                        }
                        $i++;
                    }

                    // Add semicolon
                    if ($i < count($tokens) && $tokens[$i] === ';') {
                        $output .= ';';
                    }

                    // Add blank line and ALL use statements
                    $output .= "\n\n" . $use_statements;
                    $use_statements_added = true;

                    // Skip any existing use statements and whitespace after namespace
                    $i++;
                    while ($i < count($tokens)) {
                        if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                            $i++;
                        } elseif (is_array($tokens[$i]) && $tokens[$i][0] === T_USE) {
                            // Skip this use statement
                            while ($i < count($tokens) && $tokens[$i] !== ';') {
                                $i++;
                            }
                            if ($i < count($tokens) && $tokens[$i] === ';') {
                                $i++;
                            }
                        } else {
                            break;
                        }
                    }
                    $i--; // Back up one since the for loop will increment
                } else {
                    $output .= $token[1];
                }
            } else {
                $output .= $token;
            }
        }

        // Ensure exactly one blank line after last use statement (or namespace if no use statements)
        $lines = explode("\n", $output);
        $cleaned_lines = [];

        // Find the last use statement line
        $last_use_line = -1;
        $namespace_line = -1;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'namespace ')) {
                $namespace_line = $index;
            } elseif (str_starts_with($trimmed, 'use ') && str_ends_with($trimmed, ';')) {
                $last_use_line = $index;
            }
        }

        // Determine the anchor line (last use statement, or namespace if no use statements)
        $anchor_line = $last_use_line >= 0 ? $last_use_line : $namespace_line;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // After anchor line, ensure exactly one blank line before next non-blank content
            if ($i > $anchor_line && $anchor_line >= 0) {
                // Skip blank lines immediately after anchor
                if (trim($line) === '') {
                    continue;
                }

                // First non-blank line after anchor - add exactly one blank line before it
                $cleaned_lines[] = '';
                $cleaned_lines[] = $line;
                $anchor_line = -1; // Done processing anchor
                continue;
            }

            $cleaned_lines[] = $line;
        }

        return implode("\n", $cleaned_lines);
    }

    /**
     * Add #[Relationship] attributes to model files
     *
     * @param string $file_path Relative path from base_path()
     * @param string $content Current file content
     * @param array $step_2_manifest_data Manifest state during Phase 2
     * @return string Modified content
     */
    private static function __fix_model_relationships(string $file_path, string $content, array &$step_2_manifest_data): string
    {
        // Only process rsx/ files
        if (!str_starts_with($file_path, 'rsx/')) {
            return $content;
        }

        // Check if file has a class
        $file_metadata = $step_2_manifest_data['data']['files'][$file_path] ?? null;
        if (!$file_metadata || !isset($file_metadata['class'])) {
            return $content;
        }

        // Check if this is a model by walking up the inheritance chain
        if (!self::__is_model_class($file_metadata, $step_2_manifest_data)) {
            return $content;
        }

        // Heal existing damage BEFORE adding anything. Duplicates already in the file are
        // the residue of the walk bug fixed in __has_relationship_attribute(); collapsing
        // them first also means the detection below sees a clean, normal declaration.
        $content = self::__deduplicate_relationship_attributes($content);

        // Find and fix relationship methods
        return self::__add_relationship_attributes($content);
    }

    /**
     * Check if a class is a model by walking up its inheritance chain
     *
     * @param array $file_metadata Metadata for the file being checked
     * @param array $step_2_manifest_data Manifest data
     * @return bool True if class extends Rsx_Model_Abstract
     */
    private static function __is_model_class(array $file_metadata, array &$step_2_manifest_data): bool
    {
        // Start with the class's parent
        $parent_class = $file_metadata['extends'] ?? null;
        if (!$parent_class) {
            return false;
        }

        // Walk up the inheritance chain
        $max_depth = 10; // Prevent infinite loops
        $depth = 0;
        while ($parent_class && $depth < $max_depth) {
            // Check if we've reached Rsx_Model_Abstract (check both simple and fully qualified names)
            if ($parent_class === 'Rsx_Model_Abstract' ||
                $parent_class === 'App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract' ||
                str_ends_with($parent_class, '\\Rsx_Model_Abstract')) {
                return true;
            }

            // Find the parent class in manifest
            $parent_found = false;
            foreach ($step_2_manifest_data['data']['files'] ?? [] as $other_file => $other_metadata) {
                if (($other_metadata['class'] ?? null) === $parent_class) {
                    // Move up to this class's parent
                    $parent_class = $other_metadata['extends'] ?? null;
                    $parent_found = true;
                    break;
                }
            }

            // If we can't find the parent in manifest, stop
            if (!$parent_found) {
                break;
            }

            $depth++;
        }

        return false;
    }

    /**
     * Add #[Relationship] attributes to Laravel ORM relationship methods
     *
     * @param string $content File content
     * @return string Modified content
     */
    private static function __add_relationship_attributes(string $content): string
    {
        // Laravel relationship methods to detect
        $relationship_methods = [
            'hasMany',
            'hasOne',
            'belongsTo',
            'belongsToMany',
            'morphOne',
            'morphMany',
            'morphTo',
            'morphToMany',
            'morphedByMany',
            'hasManyThrough',
            'hasOneThrough',
        ];

        // Parse tokens for accurate processing
        $tokens = token_get_all($content);
        $modifications = [];

        // Track state
        $in_class = false;
        $brace_depth = 0;
        $class_found = false;

        for ($i = 0; $i < count($tokens); $i++) {
            // Track class entry (same logic as __extract_static_properties)
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                // Check if this is ::class constant reference
                $is_class_constant = false;
                if ($i > 0) {
                    $j = $i - 1;
                    while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j--;
                    }
                    if ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                        $is_class_constant = true;
                    }
                }
                if (!$is_class_constant) {
                    $class_found = true;
                    $brace_depth = 0;
                }
            }

            // Skip string interpolation braces
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CURLY_OPEN) {
                continue;
            }

            // Track braces
            if ($tokens[$i] === '{') {
                if ($class_found && $brace_depth === 0) {
                    $in_class = true;
                    $class_found = false;
                }
                if ($in_class) {
                    $brace_depth++;
                }
            } elseif ($tokens[$i] === '}') {
                // Check if this is closing string interpolation
                $is_string_interpolation = false;
                for ($j = $i - 1; $j >= max(0, $i - 10); $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CURLY_OPEN) {
                        $is_string_interpolation = true;
                        break;
                    }
                }
                if (!$is_string_interpolation && $in_class) {
                    $brace_depth--;
                    if ($brace_depth === 0) {
                        $in_class = false;
                    }
                }
            }

            // Look for function declarations at class level
            if ($in_class && $brace_depth === 1 && is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                // Check if this is a public non-static method
                $is_public = false;
                $is_static = false;

                // Look backwards for modifiers
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (is_array($tokens[$j])) {
                        if ($tokens[$j][0] === T_PUBLIC) {
                            $is_public = true;
                        } elseif ($tokens[$j][0] === T_STATIC) {
                            $is_static = true;
                        } elseif ($tokens[$j][0] !== T_WHITESPACE &&
                                 $tokens[$j][0] !== T_PROTECTED &&
                                 $tokens[$j][0] !== T_PRIVATE &&
                                 $tokens[$j][0] !== T_ABSTRACT &&
                                 $tokens[$j][0] !== T_FINAL) {
                            break;
                        }
                    } else {
                        break;
                    }
                }

                // Default to public if no visibility specified
                if (!$is_public && !self::__has_visibility_modifier($tokens, $i)) {
                    $is_public = true;
                }

                // Only process public non-static methods
                if ($is_public && !$is_static) {
                    // Get the function name
                    $func_name = null;
                    for ($j = $i + 1; $j < count($tokens); $j++) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                            $func_name = $tokens[$j][1];
                            break;
                        }
                        if ($tokens[$j] === '(') {
                            break;
                        }
                    }

                    if ($func_name) {
                        // Check if this method contains a relationship pattern
                        $is_relationship = self::__is_relationship_method($tokens, $i, $relationship_methods);

                        if ($is_relationship) {
                            // Check if #[\Relationship] attribute already exists
                            $has_attr = self::__has_relationship_attribute($tokens, $i);
                            if (!$has_attr) {
                                // Mark for adding attribute
                                $modifications[] = [
                                    'type' => 'add_attribute',
                                    'position' => $i,
                                    'method_name' => $func_name,
                                ];
                            }
                        }
                    }
                }
            }
        }

        // Apply modifications in reverse order to preserve positions
        if (!empty($modifications)) {
            $content = self::__apply_relationship_modifications($content, $tokens, $modifications);
        }

        return $content;
    }

    /**
     * Check if a position has visibility modifier
     */
    private static function __has_visibility_modifier(array $tokens, int $function_pos): bool
    {
        for ($j = $function_pos - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j])) {
                if (in_array($tokens[$j][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
                    return true;
                }
                if ($tokens[$j][0] !== T_WHITESPACE &&
                         $tokens[$j][0] !== T_STATIC &&
                         $tokens[$j][0] !== T_ABSTRACT &&
                         $tokens[$j][0] !== T_FINAL) {
                    break;
                }
            } else {
                break;
            }
        }

        return false;
    }

    /**
     * Check if a method contains Laravel relationship patterns
     */
    private static function __is_relationship_method(array $tokens, int $func_pos, array $relationship_methods): bool
    {
        // Find the opening brace of the function
        $func_start = null;
        $func_end = null;
        $brace_count = 0;

        for ($i = $func_pos; $i < count($tokens); $i++) {
            if ($tokens[$i] === '{') {
                if ($func_start === null) {
                    $func_start = $i;
                    $brace_count = 1;
                } else {
                    $brace_count++;
                }
            } elseif ($tokens[$i] === '}' && $func_start !== null) {
                $brace_count--;
                if ($brace_count === 0) {
                    $func_end = $i;
                    break;
                }
            }
        }

        if ($func_start === null || $func_end === null) {
            return false;
        }

        // Look for "return $this->relationshipMethod(" pattern
        for ($i = $func_start; $i <= $func_end; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_RETURN) {
                // Look for $this->method pattern
                $j = $i + 1;
                // Skip whitespace
                while ($j < $func_end && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                // Check for $this
                if ($j < $func_end && is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE && $tokens[$j][1] === '$this') {
                    $j++;
                    // Skip whitespace
                    while ($j < $func_end && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }
                    // Check for ->
                    if ($j < $func_end && is_array($tokens[$j]) && $tokens[$j][0] === T_OBJECT_OPERATOR) {
                        $j++;
                        // Skip whitespace
                        while ($j < $func_end && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                            $j++;
                        }
                        // Check for relationship method name
                        if ($j < $func_end && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                            if (in_array($tokens[$j][1], $relationship_methods)) {
                                // Verify there's an opening parenthesis after
                                $k = $j + 1;
                                while ($k < $func_end && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                                    $k++;
                                }
                                if ($k < $func_end && $tokens[$k] === '(') {
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Does the method at $func_pos already carry #[Relationship]?
     *
     * WALKS ATTRIBUTE GROUPS, NOT TOKENS. The previous implementation walked backwards
     * token by token against an allow-list and ABORTED on anything outside it. An
     * attribute ARGUMENT is outside it - `T_CONSTANT_ENCAPSED_STRING` was never in the
     * list - so `#[Auth('is_logged_in')]` sitting between #[Relationship] and the function
     * keyword ended the walk early. The fixer concluded the attribute was missing and
     * inserted a duplicate. On EVERY build: a downstream box watched one method go 1 -> 3 -> 7
     * attributes across three builds, and since the auth-gates release makes #[Auth]
     * mandatory on gated relationship surfaces, every downstream app was on course to hit
     * it on its first gated relationship.
     *
     * Treating an attribute as a GROUP - from its closing `]` back to the `#[` that opened
     * it, by bracket count - makes the argument contents structurally irrelevant instead of
     * something the allow-list has to keep up with. Strings, numbers, nested calls, arrays:
     * all just tokens inside a group we skip over as a unit.
     *
     * @param array $tokens token_get_all() output
     * @param int $func_pos index of the T_FUNCTION token
     */
    private static function __has_relationship_attribute(array $tokens, int $func_pos): bool
    {
        foreach (self::__attribute_groups_before($tokens, $func_pos) as $group) {
            if (self::__group_names_relationship($tokens, $group['start'], $group['end'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every attribute group attached to the declaration at $func_pos, outermost last.
     *
     * Walks backwards from the function keyword over the things that may legally sit
     * between a declaration and its attributes - whitespace, comments, and modifiers -
     * collecting complete `#[...]` groups and stopping at the first token that belongs to
     * something else (the previous member's `;`, `}`, etc).
     *
     * @return array<int, array{start: int, end: int}> start = T_ATTRIBUTE index, end = closing ] index
     */
    private static function __attribute_groups_before(array $tokens, int $func_pos): array
    {
        $groups = [];

        for ($i = $func_pos - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                // Modifiers may appear between the attributes and the function keyword.
                if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL], true)) {
                    continue;
                }

                // Anything else that is not part of an attribute ends the run.
                break;
            }

            // A closing bracket here is the end of an attribute group - find its opener.
            if ($token === ']') {
                $start = self::__attribute_group_start($tokens, $i);
                if ($start === null) {
                    break;
                }

                $groups[] = ['start' => $start, 'end' => $i];
                $i = $start;   // the loop's $i-- steps past the opener
                continue;
            }

            break;
        }

        return $groups;
    }

    /**
     * Index of the T_ATTRIBUTE token opening the group that closes at $close_index.
     *
     * `#[` is ONE token (T_ATTRIBUTE) and it counts as an open bracket; `[` inside the
     * arguments (an array literal) counts too, which is why this is a bracket count rather
     * than a search for the nearest T_ATTRIBUTE.
     */
    private static function __attribute_group_start(array $tokens, int $close_index): ?int
    {
        $depth = 0;

        for ($i = $close_index; $i >= 0; $i--) {
            $token = $tokens[$i];

            if ($token === ']') {
                $depth++;
                continue;
            }
            if ($token === '[') {
                $depth--;
                if ($depth === 0) {
                    return null;   // a plain array literal, not an attribute group
                }
                continue;
            }
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** Does the attribute group spanning [$start, $end] name Relationship? */
    private static function __group_names_relationship(array $tokens, int $start, int $end): bool
    {
        for ($i = $start + 1; $i < $end; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_STRING && $token[1] === 'Relationship') {
                return true;
            }
            if (defined('T_NAME_FULLY_QUALIFIED')
                && $token[0] === T_NAME_FULLY_QUALIFIED
                && $token[1] === '\Relationship') {
                return true;
            }
        }

        return false;
    }

    /**
     * Collapse repeated identical #[Relationship] attributes on one declaration to a single
     * occurrence, and report how many lines were removed.
     *
     * Downstream apps already carry this damage - the duplicate-insertion bug above ran on
     * every build, so an affected method accumulated copies geometrically (1 -> 3 -> 7).
     * Fixing the cause stops it growing; this heals what is already there, on the next
     * build, with no manual cleanup and no upstream_changes action item.
     *
     * Line-based on purpose: the fixer INSERTS these attributes as whole lines of its own
     * (see __apply_relationship_modifications), so a duplicate is always a standalone line,
     * and removing whole lines cannot disturb anything sharing a line with real code.
     *
     * @param string $content File content
     * @return string Content with duplicate #[Relationship] lines removed
     */
    private static function __deduplicate_relationship_attributes(string $content): string
    {
        $lines = explode("\n", $content);
        $output = [];
        $seen_in_run = false;

        foreach ($lines as $line) {
            $is_relationship_line = trim($line) === '#[Relationship]' || trim($line) === '#[\Relationship]';

            if ($is_relationship_line) {
                if ($seen_in_run) {
                    continue;   // a duplicate within the same declaration's attribute block
                }
                $seen_in_run = true;
                $output[] = $line;
                continue;
            }

            // The run of attributes ends at the declaration itself; a blank line or any
            // other member resets it too, so two different methods never share a run.
            if (trim($line) !== '' && !str_starts_with(trim($line), '#[')) {
                $seen_in_run = false;
            }

            $output[] = $line;
        }

        return implode("\n", $output);
    }

    /**
     * Apply modifications to add #[Relationship] attributes
     */
    private static function __apply_relationship_modifications(string $content, array $tokens, array $modifications): string
    {
        // Sort modifications by position in reverse order
        usort($modifications, function ($a, $b) {
            return $b['position'] - $a['position'];
        });

        $lines = explode("\n", $content);

        foreach ($modifications as $mod) {
            // Get the line number directly from the token
            // PHP tokens include line number information at index 2
            if (!isset($tokens[$mod['position']]) || !is_array($tokens[$mod['position']])) {
                shouldnt_happen("Token at position {$mod['position']} for method {$mod['method_name']} is not an array token");
            }

            // T_FUNCTION tokens always have line number at index 2
            $func_line = $tokens[$mod['position']][2] - 1; // Convert to 0-based index

            // Find the appropriate line to insert the attribute
            // We want to insert right before the function, after any docblock, but before any existing attributes
            $insert_line = $func_line; // Default to inserting right before the function

            // Look backwards to find where to insert
            // Strategy: Insert right before the function, unless there are existing attributes
            for ($i = $func_line - 1; $i >= 0; $i--) {
                $trimmed = trim($lines[$i]);

                // Skip empty lines
                if ($trimmed === '') {
                    continue;
                }

                // If we find an existing attribute, insert before it
                if (str_starts_with($trimmed, '#[')) {
                    $insert_line = $i;
                    continue;
                }

                // If we find a docblock or single-line comment, we've gone too far - stop
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*') ||
                    str_starts_with($trimmed, '*') || str_ends_with($trimmed, '*/')) {
                    break;
                }

                // We've hit something else (property, another method, etc.), stop
                break;
            }

            // Get the indentation of the function line
            $indentation = '';
            if (preg_match('/^(\s*)/', $lines[$func_line], $matches)) {
                $indentation = $matches[1];
            }

            // Insert the #[\Relationship] attribute
            array_splice($lines, $insert_line, 0, $indentation . '#[\Relationship]');
        }

        return implode("\n", $lines);
    }

    /**
     * Remove leading backslashes from all attributes in PHP class files
     *
     * Converts #[\Route(...)] to #[Route(...)] for all attributes
     * Only processes files that contain a class definition
     *
     * @param string $file_path Relative path from base_path()
     * @param string $content File content
     * @param array $step_2_manifest_data Manifest state during Phase 2
     * @return string Modified content
     */
    private static function __fix_attribute_namespacing(string $file_path, string $content, array &$step_2_manifest_data): string
    {
        // Get file metadata
        $file_metadata = $step_2_manifest_data['data']['files'][$file_path] ?? null;
        if (!$file_metadata) {
            return $content;
        }

        // Only process files with classes
        if (!isset($file_metadata['class'])) {
            return $content;
        }

        $tokens = token_get_all($content);
        $modifications = [];

        for ($i = 0; $i < count($tokens); $i++) {
            // Look for T_ATTRIBUTE tokens (which is the #[ part)
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_ATTRIBUTE) {
                // Find the next non-whitespace token after #[
                $j = $i + 1;
                while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }

                // Check if next token is T_NAME_FULLY_QUALIFIED (e.g., \Relationship)
                if ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_NAME_FULLY_QUALIFIED) {
                    $fully_qualified_name = $tokens[$j][1];
                    // If it starts with backslash, it needs to be removed
                    if (str_starts_with($fully_qualified_name, '\\')) {
                        $attribute_name = ltrim($fully_qualified_name, '\\');
                        $modifications[] = [
                            'line' => $tokens[$j][2],
                            'attribute_name' => $attribute_name,
                        ];
                    }
                }
            }
        }

        // Apply modifications
        if (empty($modifications)) {
            return $content;
        }

        return static::__apply_attribute_backslash_modifications($content, $modifications);
    }

    /**
     * Apply modifications to remove leading backslashes from attributes
     */
    private static function __apply_attribute_backslash_modifications(string $content, array $modifications): string
    {
        $lines = explode("\n", $content);

        foreach ($modifications as $mod) {
            $line_index = $mod['line'] - 1; // Convert to 0-based
            $attr_name = $mod['attribute_name'];

            // Find and remove the backslash before the attribute name
            // Use a pattern that matches #[ followed by optional whitespace, then backslash, then the attribute name
            $pattern = '/(#\[\s*)\\\\(' . preg_quote($attr_name, '/') . ')(\s*[\(\]])/';
            $replacement = '$1$2$3';

            if (preg_match($pattern, $lines[$line_index])) {
                $lines[$line_index] = preg_replace($pattern, $replacement, $lines[$line_index], 1);
            }
        }

        return implode("\n", $lines);
    }
}
