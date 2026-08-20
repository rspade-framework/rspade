<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Bundle\Minifier;
use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Rsx;
use Exception;
use Illuminate\Console\Command;
use App\RSpade\Core\Console\Rsx_Artisan;

/**
 * Build all production assets
 *
 * Rebuilds manifest and compiles all bundles for production/debug mode.
 * Must be run before the application can serve pages in production-like modes.
 */
class Prod_Build_Command extends Command
{
    protected $signature = 'rsx:prod:build
                            {--force : Force rebuild even if assets exist}
                            {--authorized : Internal: authorized rebuild context - set by rsx:prod:enable/refresh/export. Humans should use those commands, not this flag}
                            {--skip-laravel-cache : Skip Laravel config/route/view caching}';

    protected $description = 'Build all production assets (manifest, bundles, caches)';

    public function handle(): int
    {
        // A sealed prod build is immutable. The ONLY way to rebuild it is through
        // an authorized rebuild context (rsx:prod:enable / rsx:prod:refresh, which
        // pass the --authorized invocation flag to this subprocess). A plain
        // invocation while sealed is refused so a developer cannot silently diverge
        // the build.
        if (Rsx_Prod_Seal::is_sealed() && !Rsx_Prod_Seal::is_authorized()) {
            $this->error('System is in sealed prod mode. This build is immutable.');
            $this->line('  To rebuild the sealed assets: php artisan rsx:prod:refresh');
            $this->line('  To leave prod mode:           php artisan rsx:prod:disable');

            return 1;
        }

        if (Rsx::is_development()) {
            $this->warn('Warning: Building production assets in development mode.');
            $this->line('Assets will be built but will not be used until you switch modes:');
            $this->line('  php artisan rsx:mode:set debug');
            $this->line('  php artisan rsx:mode:set production');
            $this->newLine();
        }

        $this->info('Building production assets...');
        $this->newLine();

        $start_time = microtime(true);

        // Step 1: Rebuild manifest
        $this->line('[1/5] Building manifest...');
        try {
            // Enable force build mode to allow rebuilding in production-like modes
            Manifest::$_force_build = true;

            // Force a fresh manifest rebuild by clearing and re-initializing
            Manifest::clear();
            Manifest::init();
            $this->line('      Manifest built successfully');
        } catch (Exception $e) {
            $this->error('      Failed to build manifest: ' . $e->getMessage());

            return 1;
        } finally {
            Manifest::$_force_build = false;
        }

        // Everything below is the build pipeline, and only the build pipeline may populate
        // the CDN mirror (Cdn_Cache refuses a request-time download in a mirroring mode).
        Cdn_Cache::$_build_phase = true;

        // Step 2: Mirror declared external assets.
        //
        // A sealed build is SELF-CONTAINED: every mirrorable external resource is served
        // from our own /_vendor/ copy, so the mirror must be complete before anything
        // renders. Bundle CDN assets are mirrored lazily by the compiler (step 3), but a
        // *.externals.php declaration is loaded by the client at runtime and nothing in the
        // compile touches the network for it - hence this step, which downloads exactly what
        // Rsx_Externals::resolve_url() will name.
        //
        // Runs regardless of the current mode: filenames are mode-independent, and building
        // prod assets from development mode must still populate the mirror those assets need.
        $this->line('[2/5] Mirroring external assets...');
        if (!$this->_mirror_declared_externals()) {
            return 1;
        }

        // Step 3: Compile all bundles
        $this->line('[3/5] Compiling bundles...');

        // Force restart minify server to pick up any code changes
        if (Rsx::is_production()) {
            Minifier::force_restart();
        }

        $manifest_data = Manifest::get_all();
        $bundle_classes = [];

        foreach ($manifest_data as $file_info) {
            $class_name = $file_info['class'] ?? null;
            if ($class_name && Manifest::php_is_subclass_of($class_name, 'Rsx_Module_Bundle_Abstract')) {
                $fqcn = $file_info['fqcn'] ?? $class_name;
                $bundle_classes[$fqcn] = $class_name;
            }
        }

        if (empty($bundle_classes)) {
            $this->warn('      No bundles found in manifest');
        } else {
            // Ensure storage directory exists
            $bundle_dir = storage_path('rsx-build/bundles');
            if (!is_dir($bundle_dir)) {
                mkdir($bundle_dir, 0755, true);
            }

            $compiled_count = 0;
            $failed_bundles = [];

            foreach ($bundle_classes as $fqcn => $class_name) {
                $this->line("      Compiling: {$class_name}");

                try {
                    $compiler = new BundleCompiler();
                    $compiled = $compiler->compile($fqcn, ['force_build' => true]);

                    // Get output file info (always vendor/app split)
                    $js_size = 0;
                    $css_size = 0;

                    if (isset($compiled['vendor_js_bundle_path'])) {
                        $js_size += filesize("{$bundle_dir}/{$compiled['vendor_js_bundle_path']}");
                    }
                    if (isset($compiled['app_js_bundle_path'])) {
                        $js_size += filesize("{$bundle_dir}/{$compiled['app_js_bundle_path']}");
                    }
                    if (isset($compiled['vendor_css_bundle_path'])) {
                        $css_size += filesize("{$bundle_dir}/{$compiled['vendor_css_bundle_path']}");
                    }
                    if (isset($compiled['app_css_bundle_path'])) {
                        $css_size += filesize("{$bundle_dir}/{$compiled['app_css_bundle_path']}");
                    }

                    $this->line('        JS: ' . $this->_format_size($js_size) . ', CSS: ' . $this->_format_size($css_size));
                    $compiled_count++;
                } catch (Exception $e) {
                    $this->error("        Failed: {$e->getMessage()}");
                    $failed_bundles[$class_name] = $e->getMessage();
                }
            }

            if (!empty($failed_bundles)) {
                $this->error("      {$compiled_count} compiled, " . count($failed_bundles) . ' failed');
                Cdn_Cache::$_build_phase = false;

                return 1;
            }

            $this->line("      {$compiled_count} bundles compiled");
        }

        // Release the minify RPC server now that all bundles are compiled. Leaving
        // a stale unix socket around would confuse the next build's force_restart.
        if (Rsx::is_production()) {
            Minifier::stop_rpc_server(true);
        }

        // Step 4: Optimize the composer autoloader. This MUST run BEFORE the Laravel
        // caches below: composer's post-autoload-dump hook runs Laravel's
        // ComposerScripts::clearCompiled(), which unlinks the cached config/services
        // - so caching Laravel first and dumping the autoloader second would wipe the
        // freshly-built config cache. --classmap-authoritative is strict-production
        // only (it forbids filesystem fallback in composer's own autoloader); it
        // covers ONLY the system/ PSR-4 tree, while /rsx classes resolve through the
        // manifest autoloader (a separately registered spl autoloader), so the
        // class-override pattern is unaffected.
        $this->line('[4/5] Optimizing composer autoloader...');
        if ($this->_composer_available()) {
            $strict_prod = Rsx::is_production() && !Rsx::is_debug();
            $flags = '--optimize --no-interaction' . ($strict_prod ? ' --classmap-authoritative' : '');
            passthru('bash -c ' . escapeshellarg('cd ' . escapeshellarg(base_path()) . ' && composer dump-autoload ' . $flags), $exit_code);
            if ($exit_code !== 0) {
                $this->error('      composer dump-autoload failed (exit ' . $exit_code . ')');
                Cdn_Cache::$_build_phase = false;

                return 1;
            }
            $this->line('      Composer autoloader optimized' . ($strict_prod ? ' (classmap-authoritative)' : ''));
        } else {
            $this->warn('      composer not found on PATH - skipping autoloader optimization');
        }

        // Step 5: Laravel caches (config/routes/events) - LAST, so nothing wipes them.
        if (!$this->option('skip-laravel-cache')) {
            $this->line('[5/5] Building Laravel caches...');

            // Run LOUD - no stderr suppression - and treat a non-zero exit as a
            // build failure. The optimize:cache override accepts an authorized build
            // context OR APP_ENV=production; a real failure here must not be
            // swallowed. Authorization is NOT inherited via env - we forward the
            // --authorized invocation flag explicitly to the child.
            $authorized_args = $this->option('authorized')
                ? [Rsx_Prod_Seal::AUTHORIZED_FLAG]
                : [];
            $exit_code = Rsx_Artisan::passthru('optimize:cache', $authorized_args);
            if ($exit_code !== 0) {
                $this->error('      Laravel cache optimization failed (exit ' . $exit_code . ')');
                Cdn_Cache::$_build_phase = false;

                return 1;
            }
            $this->line('      Laravel caches built');
        } else {
            $this->line('[5/5] Skipping Laravel caches (--skip-laravel-cache)');
        }

        Cdn_Cache::$_build_phase = false;

        $elapsed = round(microtime(true) - $start_time, 2);

        $this->newLine();
        $this->info("[OK] Production build complete ({$elapsed}s)");

        if (Rsx::is_development()) {
            $this->newLine();
            $this->line('To use these assets, switch to production mode:');
            $this->line('  php artisan rsx:mode:set production');
        }

        return 0;
    }

    /**
     * Download every declared external asset the build will serve from /_vendor/.
     *
     * One code path with the resolver: the asset type passed here is the SAME
     * Rsx_Externals::url_asset_type() that resolve_url() uses to name the file, so what is
     * written is exactly what a page will ask for. mirror:false entries are skipped - they
     * are declared to stay on their own origin in every mode.
     *
     * A failed download fails the build (Cdn_Cache throws).
     *
     * @return bool false when the build should abort
     */
    private function _mirror_declared_externals(): bool
    {
        $mirrored = 0;

        foreach (Rsx_Externals::all() as $identifier => $entry) {
            if (!$entry['mirror']) {
                $this->line("      Skipping (mirror:false): {$identifier}");
                continue;
            }

            foreach (array_merge($entry['js'], $entry['css']) as $url) {
                $type = Rsx_Externals::url_asset_type($url);
                $filename = Cdn_Cache::get_cache_filename($url, $type);
                $cached = Cdn_Cache::is_cached($url, $type);

                $this->line("      {$identifier}: {$url}");
                $this->line('        -> ' . $filename . ($cached ? ' (cached)' : ' (downloading)'));

                try {
                    Cdn_Cache::get($url, $type);
                } catch (Exception $e) {
                    $this->error("        Failed: {$e->getMessage()}");
                    Cdn_Cache::$_build_phase = false;

                    return false;
                }

                $mirrored++;
            }
        }

        $this->line("      {$mirrored} external assets mirrored");

        return true;
    }

    /**
     * Is the composer binary available on PATH?
     */
    private function _composer_available(): bool
    {
        $path = trim((string) @shell_exec('bash -c ' . escapeshellarg('command -v composer 2>/dev/null')));

        return $path !== '';
    }

    private function _format_size(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }
}
