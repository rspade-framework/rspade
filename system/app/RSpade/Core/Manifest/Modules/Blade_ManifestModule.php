<?php

namespace App\RSpade\Core\Manifest\Modules;

use RuntimeException;
use App\RSpade\Core\Manifest\ManifestModule_Abstract;

/**
 * Module for processing Blade template files in the manifest
 */
class Blade_ManifestModule extends ManifestModule_Abstract
{
    /**
     * Get file extensions this module handles
     */
    public function handles(): array
    {
        return ['blade.php'];
    }

    /**
     * Get processing priority
     */
    public function priority(): int
    {
        return 15; // Process before regular PHP but after high priority
    }

    /**
     * Remove Blade comments from content
     *
     * @param string $content Blade content
     * @return string Content with comments removed
     */
    protected function remove_blade_comments(string $content): string
    {
        // Remove {{-- --}} style comments
        $content = preg_replace('/\{\{--.*?--\}\}/s', '', $content);

        return $content;
    }

    /**
     * Process a Blade file and extract metadata
     */
    public function process(string $file_path, array $metadata): array
    {
        // This happens during phase 2 of manifest build, and is automatically incrementally cached by step 2

        // Only process .blade.php files
        if (!str_ends_with($file_path, '.blade.php')) {
            return $metadata;
        }

        $content = file_get_contents($file_path);
        $metadata['type'] = 'view';

        // Check if file is in rsx directory (only RSX files need @rsx_id)
        $is_rsx_file = str_contains($file_path, '/rsx/');

        // For RSX files, check if file has meaningful content after comment removal
        if ($is_rsx_file) {
            $cleaned_content = $this->remove_blade_comments($content);
            $trimmed_content = trim($cleaned_content);

            // If file is effectively empty after comments removed and trimmed, don't require @rsx_id
            if (empty($trimmed_content)) {
                // Still extract view name but don't require an ID
                // Convert all backslashes to forward slashes for consistency
                $normalized_file_path = str_replace('\\', '/', $file_path);
                $normalized_base_path = str_replace('\\', '/', base_path());

                // Strip base path to get relative path
                if (str_starts_with($normalized_file_path, $normalized_base_path)) {
                    $relative_path = substr($normalized_file_path, strlen($normalized_base_path));
                    $relative_path = ltrim($relative_path, '/');
                } else {
                    // Already relative
                    $relative_path = $normalized_file_path;
                }

                $view_name = $this->path_to_view_name($relative_path);
                $metadata['view_name'] = $view_name;
                $metadata['relative_path'] = $relative_path;

                return $metadata;
            }
        }

        // Extract @rsx_id directive (path-agnostic identifier)
        if (preg_match('/@rsx_id\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $matches)) {
            $rsx_id = $matches[1];

            // Validate RSX ID format: must start with uppercase, have at least 2 segments separated by underscores
            if (!preg_match('/^[A-Z][A-Za-z0-9]*(_[A-Za-z0-9]+)+$/', $rsx_id)) {
                throw new RuntimeException(
                    "Invalid RSX ID format in {$file_path}\n" .
                    "  RSX ID: '{$rsx_id}'\n" .
                    "  RSX IDs must:\n" .
                    "    - Start with an uppercase letter (A-Z)\n" .
                    "    - Have at least 2 segments separated by underscores\n" .
                    "    - Contain only alphanumeric characters (A-Z, a-z, 0-9) and underscores\n" .
                    "  Convention: First segment describes the name, second describes the type\n" .
                    "  Examples: 'User_View', 'Login_Form_2', 'Frontend_Contacts_View_2'"
                );
            }

            $metadata['id'] = $rsx_id;
        } elseif ($is_rsx_file) {
            // Non-empty RSX file must have an @rsx_id - fail loud
            throw new RuntimeException(
                "RSX Blade template in {$file_path} has content but no @rsx_id directive. " .
                'Please add an @rsx_id directive to the file.'
            );
        }

        // Extract view name from path - ensure proper path normalization
        // Convert all backslashes to forward slashes for consistency
        $normalized_file_path = str_replace('\\', '/', $file_path);
        $normalized_base_path = str_replace('\\', '/', base_path());

        // Strip base path to get relative path
        if (str_starts_with($normalized_file_path, $normalized_base_path)) {
            $relative_path = substr($normalized_file_path, strlen($normalized_base_path));
            $relative_path = ltrim($relative_path, '/');
        } else {
            // Already relative
            $relative_path = $normalized_file_path;
        }

        $view_name = $this->path_to_view_name($relative_path);
        $metadata['view_name'] = $view_name;

        // Extract RSX extends directive (path-agnostic)
        if (preg_match('/@rsx_extends\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $matches)) {
            $rsx_extends = $matches[1];

            // Validate RSX extends format: must match RSX ID format (no periods, must be valid ID)
            if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $rsx_extends)) {
                // Check if periods were used (common mistake from Laravel @extends)
                if (str_contains($rsx_extends, '.')) {
                    throw new RuntimeException(
                        "Invalid @rsx_extends format in {$file_path}\n" .
                        "  @rsx_extends('{$rsx_extends}')\n" .
                        "  ERROR: @rsx_extends should use RSX IDs, not path notation with periods.\n" .
                        "  \n" .
                        "  The @rsx_extends directive must reference the @rsx_id from another blade file.\n" .
                        "  For example:\n" .
                        "    - If the layout has: @rsx_id('Dashboard_Layout')\n" .
                        "    - Then use: @rsx_extends('Dashboard_Layout')\n" .
                        "    - NOT: @rsx_extends('app.dashboard.layout')\n" .
                        "  \n" .
                        "  RSX IDs must:\n" .
                        "    - Start with an uppercase letter (A-Z)\n" .
                        "    - Contain only alphanumeric characters (A-Z, a-z, 0-9) and underscores (_)\n" .
                        '    - Match exactly the @rsx_id defined in the target blade file'
                    );
                }

                throw new RuntimeException(
                    "Invalid @rsx_extends format in {$file_path}\n" .
                    "  @rsx_extends('{$rsx_extends}')\n" .
                    "  RSX IDs must:\n" .
                    "    - Start with an uppercase letter (A-Z)\n" .
                    "    - Contain only alphanumeric characters (A-Z, a-z, 0-9) and underscores (_)\n" .
                    "  Example: @rsx_extends('Dashboard_Layout')"
                );
            }

            $metadata['rsx_extends'] = $rsx_extends;
        }

        // Extract standard extends directive
        if (preg_match('/@extends\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $matches)) {
            $metadata['extends'] = $matches[1];
        }

        // Extract section names
        $sections = [];
        if (preg_match_all('/@section\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,|\))/', $content, $matches)) {
            $sections = $matches[1];
        }

        if (!empty($sections)) {
            $metadata['sections'] = array_unique($sections);
        }

        // Extract yielded sections
        $yields = [];
        if (preg_match_all('/@yield\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $matches)) {
            $yields = $matches[1];
        }

        if (!empty($yields)) {
            $metadata['yields'] = array_unique($yields);
        }

        // Extract RSX included views (path-agnostic)
        $rsx_includes = [];
        if (preg_match_all('/@rsx_include\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,|\))/', $content, $matches)) {
            $rsx_includes = $matches[1];

            // Validate each RSX include format
            foreach ($rsx_includes as $rsx_include) {
                if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $rsx_include)) {
                    // Check if periods were used (common mistake from Laravel @include)
                    if (str_contains($rsx_include, '.')) {
                        throw new RuntimeException(
                            "Invalid @rsx_include format in {$file_path}\n" .
                            "  @rsx_include('{$rsx_include}')\n" .
                            "  ERROR: @rsx_include should use RSX IDs, not path notation with periods.\n" .
                            "  \n" .
                            "  The @rsx_include directive must reference the @rsx_id from another blade file.\n" .
                            "  For example:\n" .
                            "    - If the partial has: @rsx_id('User_Card')\n" .
                            "    - Then use: @rsx_include('User_Card')\n" .
                            "    - NOT: @rsx_include('partials.user.card')\n" .
                            "  \n" .
                            "  RSX IDs must:\n" .
                            "    - Start with an uppercase letter (A-Z)\n" .
                            "    - Contain only alphanumeric characters (A-Z, a-z, 0-9) and underscores (_)\n" .
                            '    - Match exactly the @rsx_id defined in the target blade file'
                        );
                    }

                    throw new RuntimeException(
                        "Invalid @rsx_include format in {$file_path}\n" .
                        "  @rsx_include('{$rsx_include}')\n" .
                        "  RSX IDs must:\n" .
                        "    - Start with an uppercase letter (A-Z)\n" .
                        "    - Contain only alphanumeric characters (A-Z, a-z, 0-9) and underscores (_)\n" .
                        "  Example: @rsx_include('User_Card')"
                    );
                }
            }
        }

        if (!empty($rsx_includes)) {
            $metadata['rsx_includes'] = array_unique($rsx_includes);
        }

        // Extract standard included views
        $includes = [];
        if (preg_match_all('/@include\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,|\))/', $content, $matches)) {
            $includes = $matches[1];
        }

        if (!empty($includes)) {
            $metadata['includes'] = array_unique($includes);
        }

        // Extract components
        $components = [];
        if (preg_match_all('/@component\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $matches)) {
            $components = array_merge($components, $matches[1]);
        }

        // Extract x-components (Blade components)
        if (preg_match_all('/<x-([a-zA-Z0-9\-.:]+)/', $content, $matches)) {
            $components = array_merge($components, $matches[1]);
        }

        if (!empty($components)) {
            $metadata['components'] = array_unique($components);
        }

        // Extract slots
        $slots = [];
        if (preg_match_all('/@slot\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $matches)) {
            $slots = $matches[1];
        }

        // Extract x-slot
        if (preg_match_all('/<x-slot\s+(?:name=)?[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $slots = array_merge($slots, $matches[1]);
        }

        if (!empty($slots)) {
            $metadata['slots'] = array_unique($slots);
        }

        // Check if it's a layout
        if (!empty($yields) && empty($metadata['extends'])) {
            $metadata['is_layout'] = true;
        }

        // Check if it's a component
        if (preg_match('/@props\s*\(/', $content)) {
            $metadata['is_component'] = true;

            // Extract props
            if (preg_match('/@props\s*\(\s*\[([^\]]+)\]\s*\)/', $content, $matches)) {
                $props_string = $matches[1];
                $props = [];

                if (preg_match_all('/[\'"](\w+)[\'"]/', $props_string, $prop_matches)) {
                    $props = $prop_matches[1];
                }

                if (!empty($props)) {
                    $metadata['props'] = $props;
                }
            }
        }

        // Extract Livewire components
        if (preg_match_all('/@livewire\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,|\))/', $content, $matches)) {
            $metadata['livewire'] = array_unique($matches[1]);
        }

        // Check for authentication directives
        $auth_directives = [];
        if (str_contains($content, '@auth')) {
            $auth_directives[] = 'auth';
        }
        if (str_contains($content, '@guest')) {
            $auth_directives[] = 'guest';
        }
        if (str_contains($content, '@can')) {
            $auth_directives[] = 'can';
        }

        if (!empty($auth_directives)) {
            $metadata['auth_directives'] = $auth_directives;
        }

        // Extract custom directives
        $custom_directives = [];
        if (preg_match_all('/@(\w+)(?:\s*\(|$)/', $content, $matches)) {
            $standard_directives = [
                'extends', 'section', 'yield', 'include', 'component', 'slot', 'props',
                'if', 'elseif', 'else', 'endif', 'unless', 'endunless',
                'for', 'foreach', 'forelse', 'while', 'endfor', 'endforeach', 'endforelse', 'endwhile',
                'continue', 'break', 'php', 'endphp', 'push', 'endpush', 'stack',
                'auth', 'guest', 'can', 'cannot', 'canany', 'endauth', 'endguest', 'endcan', 'endcannot', 'endcanany',
                'isset', 'empty', 'endisset', 'endempty', 'switch', 'case', 'default', 'endswitch',
                'json', 'method', 'csrf', 'dd', 'dump', 'env', 'production', 'endproduction',
                'error', 'enderror', 'errors', 'class', 'style', 'checked', 'selected', 'disabled', 'readonly', 'required',
                // RSX directives
                'rsx_id', 'rsx_extends', 'rsx_include',
            ];

            foreach ($matches[1] as $directive) {
                if (!in_array($directive, $standard_directives) && !in_array($directive, $custom_directives)) {
                    $custom_directives[] = $directive;
                }
            }
        }

        if (!empty($custom_directives)) {
            $metadata['custom_directives'] = array_unique($custom_directives);
        }

        // Store relative path for view resolution
        $metadata['relative_path'] = $relative_path;

        return $metadata;
    }

    /**
     * Convert file path to Laravel view name
     */
    protected function path_to_view_name(string $path): string
    {
        // Remove .blade.php extension
        $path = preg_replace('/\.blade\.php$/', '', $path);

        // Handle different view locations
        if (str_starts_with($path, 'resources/views/')) {
            $path = substr($path, strlen('resources/views/'));
        } elseif (str_starts_with($path, 'rsx/views/')) {
            $path = 'rsx::' . substr($path, strlen('rsx/views/'));
        }

        // Convert path separators to dots
        return str_replace('/', '.', $path);
    }
}
