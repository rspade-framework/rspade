<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Bundle\Bundle_Include_Pattern;
use App\RSpade\Core\Bundle\Minifier;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A bundle served to visitors who have not signed in publishes none of this application's
 * internals.
 *
 * A bundle takes its audience from its LEAST privileged page, and everything a bundle
 * includes is downloaded by that audience. The three bundles in PUBLIC_BUNDLES are rendered
 * by pages anybody can load, so each names its assets by file - and this class fails when
 * an edit widens one:
 *
 *   - the list is complete: every bundle a Blade layout prints is listed here when a
 *     public page route is declared beside that layout;
 *   - no entry of a listed bundle is a directory of the shared trees (rsx/theme, rsx/lib,
 *     rsx/models), and no pattern descends into one;
 *   - the application models a listed bundle includes are exactly the ones it declares
 *     here (none, as shipped);
 *   - the COMPILED output - what the browser is handed, with comments removed as a
 *     production build removes them - names no application controller that carries a
 *     gated Ajax endpoint and no application model, and defines no application class,
 *     component or component stylesheet, other than those in the files the bundle lists.
 *
 * Nothing about what is internal is written down here: it is every class, component,
 * model and gated controller the manifest finds under rsx/, minus what the bundle's own
 * resolved file list declares. A new screen anywhere in the application is covered the
 * day it is added.
 */
class Public_Bundle_Contents_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The bundles whose least privileged page is public, each with the application models
     * it deliberately includes.
     */
    private const PUBLIC_BUNDLES = [
        'Login_Bundle' => ['models' => []],
        'Errors_Bundle' => ['models' => []],
        'Portal_Auth_Bundle' => ['models' => []],
    ];

    /**
     * The trees every module shares. A public bundle takes files from them, never a
     * directory.
     */
    private const SHARED_TREES = ['rsx/theme', 'rsx/lib', 'rsx/models'];

    /**
     * Compiled output per bundle, kept for the class: ['js' => minified, 'css' => no comments].
     */
    private static array $compiled = [];

    private static function __fqcn(string $bundle): string
    {
        $record = Manifest::php_class_metadata($bundle);

        static::__assert_not_null($record, "{$bundle} is a class the manifest knows");

        return $record['fqcn'];
    }

    /**
     * The project-relative files a bundle resolves to.
     *
     * @return array<string,bool>
     */
    private static function __included_files(string $bundle): array
    {
        $resolved = (new BundleCompiler())->resolve_for_shake(static::__fqcn($bundle));
        $included = [];

        foreach ($resolved['files'] as $file) {
            $included[_rsx_relative_build_path($file)] = true;
        }

        return $included;
    }

    /**
     * What the browser is handed for a bundle's application bucket, as a production build
     * hands it over: JavaScript minified (which removes every comment and the development
     * sourcemap), stylesheet with its comments removed.
     *
     * @return array{js: string, css: string}
     */
    private static function __compiled(string $bundle): array
    {
        if (!isset(static::$compiled[$bundle])) {
            $result = (new BundleCompiler())->compile(static::__fqcn($bundle), []);
            $directory = Rsx_Project_Paths::bundles_dir() . '/';

            static::__assert_not_empty($result['app_js_bundle_path'] ?? null, "{$bundle} compiles an application script");
            static::__assert_not_empty($result['app_css_bundle_path'] ?? null, "{$bundle} compiles an application stylesheet");

            static::$compiled[$bundle] = [
                'js' => Minifier::minify_js(file_get_contents($directory . $result['app_js_bundle_path']), $bundle . '.js'),
                'css' => preg_replace('~/\*.*?\*/~s', '', file_get_contents($directory . $result['app_css_bundle_path'])),
            ];
        }

        return static::$compiled[$bundle];
    }

    /**
     * Every name this application declares under rsx/, split by whether the bundle's own
     * file list declares it.
     *
     * A name is 'own' when ANY included file declares it, so a script and a PHP class that
     * share a name are judged by the included one.
     *
     * @return array{own: array<string,array>, internal: array<string,array>} name => [kind, file]
     */
    private static function __application_names(array $included): array
    {
        $models = Manifest::php_class_records_extending('Rsx_Model_Abstract');
        $gated_controllers = [];

        foreach (Auth_Gates::get_surfaces() as $target => $surface) {
            if (in_array('ajax', $surface['kinds'] ?? [], true) && array_values($surface['auth'] ?? []) !== ['public']) {
                $gated_controllers[explode('::', $target)[0]] = true;
            }
        }

        $own = [];
        $internal = [];

        foreach (Manifest::get_all() as $file => $metadata) {
            if (!str_starts_with($file, 'rsx/') || str_starts_with($file, 'rsx/tests/')) {
                continue;
            }

            $declared = [];
            $extension = $metadata['extension'] ?? '';

            if ($extension === 'js' && !empty($metadata['class'])) {
                $declared[$metadata['class']] = 'class';
            } elseif ($extension === 'jqhtml' && !empty($metadata['id'])) {
                $declared[$metadata['id']] = 'component';
            } elseif ($extension === 'php' && !empty($metadata['class'])) {
                if (isset($models[$metadata['class']])) {
                    $declared[$metadata['class']] = 'model';
                } elseif (isset($gated_controllers[$metadata['class']])) {
                    $declared[$metadata['class']] = 'controller';
                }
            }

            foreach ($declared as $name => $kind) {
                if (isset($included[$file])) {
                    $own[$name] = [$kind, $file];
                } else {
                    $internal[$name] = [$kind, $file];
                }
            }
        }

        return ['own' => $own, 'internal' => array_diff_key($internal, $own)];
    }

    private static function __names_whole_word(string $name, string $js): bool
    {
        return (bool) preg_match('/(?<![A-Za-z0-9_$])' . preg_quote($name, '/') . '(?![A-Za-z0-9_$])/', $js);
    }

    private static function __defines_class(string $name, string $js): bool
    {
        return (bool) preg_match('/(?<![A-Za-z0-9_$])class\s+' . preg_quote($name, '/') . '(?![A-Za-z0-9_$])/', $js);
    }

    private static function __defines_template(string $name, string $js): bool
    {
        return static::__names_whole_word('template_' . $name, $js);
    }

    /**
     * Does the stylesheet carry a rule for this component: `.Name`, `.Name__child`,
     * `.Name--modifier` - and not `.Name_Of_Another_Component`.
     */
    private static function __styles_component(string $name, string $css): bool
    {
        return (bool) preg_match('/\.' . preg_quote($name, '/') . '(?:__|--|[^A-Za-z0-9_-])/', $css);
    }

    /**
     * A page route nobody has to sign in for.
     */
    private static function __is_public_page(array $surface): bool
    {
        return (bool) array_intersect($surface['kinds'] ?? [], ['route', 'portal_route'])
            && array_values($surface['auth'] ?? []) === ['public'];
    }

    public static function test_every_bundle_rendered_beside_a_public_page_is_listed()
    {
        $public_directories = [];

        foreach (Auth_Gates::get_surfaces() as $surface) {
            $file = (string) ($surface['file'] ?? '');

            if (str_starts_with($file, 'rsx/') && static::__is_public_page($surface)) {
                $public_directories[dirname($file)] = true;
            }
        }

        static::__assert_not_empty($public_directories, 'the manifest lists public page routes');

        $root = base_path('rsx');
        $printed = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (!preg_match_all('/([A-Za-z0-9_]+_Bundle)::render\(/', file_get_contents($file->getPathname()), $matches)) {
                continue;
            }

            $layout_directory = 'rsx' . substr($file->getPath(), strlen($root));

            foreach ($matches[1] as $bundle) {
                $printed[$bundle][] = $layout_directory;
            }
        }

        foreach (array_keys(self::PUBLIC_BUNDLES) as $bundle) {
            static::__assert_array_has_key($bundle, $printed, "{$bundle} is printed by a Blade layout");
        }

        foreach ($printed as $bundle => $layout_directories) {
            foreach ($layout_directories as $layout_directory) {
                foreach (array_keys($public_directories) as $public_directory) {
                    $beside = $public_directory === $layout_directory
                        || str_starts_with($public_directory, $layout_directory . '/');

                    if ($beside) {
                        static::__assert_array_has_key(
                            $bundle,
                            self::PUBLIC_BUNDLES,
                            "{$bundle} is printed by a layout in {$layout_directory}, and {$public_directory} declares a "
                                . 'page route with #[Auth(\'public\')]: the bundle is served to visitors who have not signed in. '
                                . 'Give those pages a bundle of their own that names its assets by file, and list it in PUBLIC_BUNDLES'
                        );
                    }
                }
            }
        }
    }

    public static function test_a_public_bundle_includes_no_shared_directory()
    {
        foreach (array_keys(self::PUBLIC_BUNDLES) as $bundle) {
            $fqcn = static::__fqcn($bundle);
            $own_directory = false;

            foreach ($fqcn::define()['include'] ?? [] as $entry) {
                if (!is_string($entry) || !str_contains($entry, '/')) {
                    continue;
                }

                $relative = trim(_rsx_relative_build_path($entry), '/');
                $recursive_pattern = false;

                if (Bundle_Include_Pattern::is_pattern($relative)) {
                    $parts = Bundle_Include_Pattern::parse($relative);
                    $relative = $parts['directory'];
                    $recursive_pattern = $parts['recursive'];
                } elseif (!is_dir(base_path($relative))) {
                    // One file, named.
                    continue;
                } else {
                    $recursive_pattern = true;
                }

                $shared = false;

                foreach (self::SHARED_TREES as $tree) {
                    if ($relative === $tree || str_starts_with($relative, $tree . '/')) {
                        $shared = true;
                    }
                }

                if (!$shared) {
                    $own_directory = $own_directory || is_dir(base_path($relative));

                    continue;
                }

                static::__assert_false(
                    $recursive_pattern,
                    "{$bundle} includes '{$entry}', which takes a shared directory and everything beneath it. "
                        . 'A bundle served to visitors who have not signed in names its theme stylesheets with the '
                        . "'<directory>/*.scss' form and each component, model and script by file"
                );
            }

            static::__assert_true($own_directory, "{$bundle} includes its own module directory, which covers the views it serves");
        }
    }

    public static function test_a_public_bundle_includes_only_the_models_it_declares()
    {
        foreach (self::PUBLIC_BUNDLES as $bundle => $declaration) {
            $names = static::__application_names(static::__included_files($bundle));
            $included_models = [];

            foreach ($names['own'] as $name => [$kind]) {
                if ($kind === 'model') {
                    $included_models[] = $name;
                }
            }

            sort($included_models);
            $declared = $declaration['models'];
            sort($declared);

            static::__assert_equals(
                $declared,
                $included_models,
                "{$bundle} includes exactly the application models this test declares for it. A model added to a public "
                    . 'bundle publishes its columns, enums and relationships to visitors who have not signed in'
            );
        }
    }

    public static function test_compiled_output_carries_no_application_internals()
    {
        foreach (array_keys(self::PUBLIC_BUNDLES) as $bundle) {
            $names = static::__application_names(static::__included_files($bundle));
            $compiled = static::__compiled($bundle);

            // The detectors are proven on this very output first: a framework class every
            // bundle carries, and everything the bundle's own files declare, must be found.
            // An empty or mis-read artifact therefore fails here instead of passing below.
            static::__assert_true(
                static::__defines_class('Rsx_Js_Model', $compiled['js']),
                "{$bundle}'s compiled script is the real artifact (it defines a framework class)"
            );

            foreach ($names['own'] as $name => [$kind, $file]) {
                if ($kind === 'class') {
                    static::__assert_true(static::__defines_class($name, $compiled['js']), "{$bundle} defines its own class {$name} ({$file})");
                } elseif ($kind === 'component') {
                    static::__assert_true(static::__defines_template($name, $compiled['js']), "{$bundle} defines its own component {$name} ({$file})");
                }
            }

            static::__assert_greater_than(100, count($names['internal']), 'the manifest lists the rest of the application');

            $found = [];

            foreach ($names['internal'] as $name => [$kind, $file]) {
                if ($kind === 'controller' || $kind === 'model') {
                    if (static::__names_whole_word($name, $compiled['js'])) {
                        $found[] = "{$kind} {$name} ({$file}) is named in the script";
                    }

                    continue;
                }

                if (static::__defines_class($name, $compiled['js'])) {
                    $found[] = "{$kind} {$name} ({$file}) is defined in the script";
                }
                if (static::__defines_template($name, $compiled['js'])) {
                    $found[] = "{$kind} {$name} ({$file}) has its template in the script";
                }
                if (static::__styles_component($name, $compiled['css'])) {
                    $found[] = "{$kind} {$name} ({$file}) has rules in the stylesheet";
                }
            }

            static::__assert_equals(
                [],
                $found,
                "{$bundle} is served to visitors who have not signed in, and its compiled output carries application "
                    . 'internals its include list does not name by file'
            );
        }
    }
}
