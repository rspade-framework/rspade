<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use App\RSpade\Core\Console\Rsx_Artisan;

/**
 * Export the application for deployment.
 *
 * Runs rsx:prod:build then assembles a runnable deployment directory. This is a strict
 * WHITELIST: it ships ONLY source code, the sealed build artifacts, and an env template -
 * everything else (uploaded-file blob store, regenerable caches, IDE bridge state,
 * framework-update working store, db backups, logs, dev/IDE cruft, .git, real .env) is
 * excluded BY DEFAULT. A whitelist means a newly-added runtime/dev directory is left out
 * automatically instead of silently leaking into a deployment package.
 */
class Prod_Export_Command extends Command
{
    protected $signature = 'rsx:prod:export
                            {--path=./rsx-export : Export destination directory}
                            {--skip-build : Skip running rsx:prod:build (use existing assets)}';

    protected $description = 'Export application for deployment';

    /**
     * Sealed-build artifacts under the storage root that MUST ship for a prod boot.
     * (Everything else under storage/ is runtime state or regenerable cache - excluded.)
     */
    private const SEALED_BUILD_FILES = [
        'rsx-build/build_key',
        'rsx-build/manifest_data.php',
        'rsx-build/prod_seal.json',
    ];
    private const SEALED_BUILD_DIRS = [
        'rsx-build/bundles',
        'rsx-build/js-stubs',
        'rsx-build/js-model-stubs',
    ];

    /**
     * Empty, writable directories the runtime expects to exist (recreated as skeletons,
     * never populated from the dev box).
     */
    private const STORAGE_SKELETON = [
        'app/public',
        'app/temp',
        'framework/cache',
        'framework/sessions',
        'framework/views',
        'logs',
        'rsx-tmp',
        'rsx-locks',
        'rsx-thumbnails',
        'rsx-renditions',
        'storage/files',
    ];

    public function handle(): int
    {
        $export_path = $this->option('path');

        // Resolve relative path from base_path parent (since we're in /system)
        if (str_starts_with($export_path, './')) {
            $export_path = dirname(base_path()) . '/' . substr($export_path, 2);
        } elseif (!str_starts_with($export_path, '/')) {
            $export_path = dirname(base_path()) . '/' . $export_path;
        }

        $this->info('Exporting application for deployment...');
        $this->line("  Destination: {$export_path}");
        $this->newLine();

        // Step 1: Run production build (unless --skip-build)
        if (!$this->option('skip-build')) {
            $this->line('[1/3] Running production build...');
            // Export is an authorized build context (it produces a deployable), so
            // the pipeline's optimize:cache step is permitted even off a production
            // APP_ENV. Authorization travels as an INVOCATION FLAG, not an env prefix.
            $exit_code = Rsx_Artisan::passthru('rsx:prod:build', [Rsx_Prod_Seal::AUTHORIZED_FLAG]);
            if ($exit_code !== 0) {
                $this->error('Production build failed');

                return 1;
            }
            $this->newLine();
        } else {
            $this->line('[1/3] Skipping production build (--skip-build)');
        }

        // Step 2: Prepare export directory
        $this->line('[2/3] Preparing export directory...');

        // Clear existing export if it exists
        if (is_dir($export_path)) {
            $this->line('      Clearing existing export...');
            $this->_clear_directory($export_path);
        }

        // Create export directory
        if (!mkdir($export_path, 0755, true) && !is_dir($export_path)) {
            $this->error("Failed to create export directory: {$export_path}");

            return 1;
        }

        // Step 3: Assemble the whitelist
        $this->line('[3/3] Copying files (whitelist)...');

        $base = dirname(base_path()); // Parent of /system (project root)
        $system = $base . '/system';
        $copied = 0;

        // --- system/ code tree: everything EXCEPT storage (handled separately) and
        //     dev-only bits. The code tree is definitionally shippable; the sensitive
        //     leaks all live under storage, which is excluded here and whitelisted below.
        $this->line('      system/ (code + vendor + node_modules)...');
        $copied += $this->_copy_tree($system, $export_path . '/system', [
            'storage',              // whitelisted separately (sealed build only)
            '.env',                 // shipped as a symlink to ../.env
            '.git',
            'app/RSpade/tests',
            'app/RSpade/resource/DebugProxy',
            'docs.dev',
            'docs.goals',
            'ace_reference',
            'bin/publish',
            '__pycache__',
            '*.expect',
        ]);

        // --- volatile storage: ONLY the sealed build artifacts + an empty writable skeleton.
        //     Storage lives at <project>/storage once relocated (marker file), at the
        //     historic system/storage before that - export it at the SAME relative
        //     location, marker included, so the deployed tree resolves identically.
        $storage_src = storage_path();
        $storage_relocated = is_file($storage_src . '/.rspade_storage_relocated');
        $storage_rel = $storage_relocated ? 'storage' : 'system/storage';
        $storage_dest = $export_path . '/' . $storage_rel;
        $this->line('      ' . $storage_rel . '/ (sealed build artifacts only)...');
        $copied += $this->_copy_sealed_build($storage_src, $storage_dest);
        $this->_create_storage_skeleton($storage_dest);
        if ($storage_relocated
            && !copy($storage_src . '/.rspade_storage_relocated', $storage_dest . '/.rspade_storage_relocated')) {
            $this->warn('      [WARNING] Failed to copy the storage relocation marker into the export.');
        }

        // --- rsx/ application tree (minus research/dev material; keep the CDN cache)
        $this->line('      rsx/ (application)...');
        $copied += $this->_copy_tree($base . '/rsx', $export_path . '/rsx', [
            '.git',
            'tests',
            'resource/research',
            'resource/archive',
            'resource/incomplete',
            '*.expect',
        ]);

        // --- root runtime files (app-layer manifests + the artisan shim)
        foreach (['artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json'] as $file) {
            if (is_file("{$base}/{$file}")) {
                copy("{$base}/{$file}", "{$export_path}/{$file}");
                $copied++;
            }
        }

        // --- root vendor/node_modules ONLY if a real app-layer install exists (in this
        //     template they are empty stubs; a downstream app with app-layer deps ships them).
        foreach (['vendor', 'node_modules'] as $dir) {
            if ($this->_has_real_install("{$base}/{$dir}")) {
                $this->line("      {$dir}/ (app-layer install)...");
                $copied += $this->_copy_tree("{$base}/{$dir}", "{$export_path}/{$dir}", ['.git']);
            }
        }

        // --- env template (never the real .env) + the system/.env symlink
        $this->_generate_env_dist("{$base}/.env", $export_path);

        // Create .gitignore for export directory
        file_put_contents_safe(
            "{$export_path}/.gitignore",
            "# This export should not be committed to version control\n*\n"
        );

        $this->newLine();
        $this->info('[OK] Export complete');
        $this->line("     {$copied} files");
        $this->line("     Location: {$export_path}");
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Copy the export directory to your production server');
        $this->line('  2. Copy .env.dist to .env and configure it on the production server');
        $this->line('  3. Point your web server document root at system/public ONLY');

        return 0;
    }

    /**
     * Copy a directory tree, excluding the given relative patterns. A pattern matches when
     * a copied entry's path (relative to $src) starts with it, contains it as a path
     * segment, or (for a leading '*') ends with its suffix (e.g. '*.expect').
     *
     * @param string $src
     * @param string $dest
     * @param array<int, string> $excludes
     * @return int Number of files copied.
     */
    private function _copy_tree(string $src, string $dest, array $excludes): int
    {
        if (!is_dir($src)) {
            $this->warn("      Skipping " . basename($src) . " (not found)");

            return 0;
        }

        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $copied = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sub_path = $iterator->getSubPathname();

            if ($this->_is_excluded($sub_path, $excludes)) {
                continue;
            }

            $dest_path = "{$dest}/{$sub_path}";

            if ($item->isDir()) {
                if (!is_dir($dest_path)) {
                    mkdir($dest_path, 0755, true);
                }
            } else {
                $parent = dirname($dest_path);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                copy($item->getPathname(), $dest_path);
                $copied++;
            }
        }

        return $copied;
    }

    /**
     * Whether $sub_path matches any exclusion pattern.
     *
     * @param string $sub_path
     * @param array<int, string> $excludes
     * @return bool
     */
    private function _is_excluded(string $sub_path, array $excludes): bool
    {
        foreach ($excludes as $pattern) {
            if (str_starts_with($pattern, '*')) {
                if (str_ends_with($sub_path, substr($pattern, 1))) {
                    return true;
                }
                continue;
            }
            if ($sub_path === $pattern
                || str_starts_with($sub_path, $pattern . '/')
                || str_contains($sub_path, "/{$pattern}/")
                || str_ends_with($sub_path, "/{$pattern}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy ONLY the sealed-build artifacts from the storage root. Everything else under
     * storage/ (blob store, thumbnails, renditions, ide-bridge, rsx-framework, db_backups,
     * logs) is runtime state or regenerable cache and must never ship.
     *
     * @param string $storage_src
     * @param string $storage_dest
     * @return int
     */
    private function _copy_sealed_build(string $storage_src, string $storage_dest): int
    {
        $copied = 0;

        foreach (self::SEALED_BUILD_FILES as $rel) {
            $src = "{$storage_src}/{$rel}";
            if (is_file($src)) {
                $dest = "{$storage_dest}/{$rel}";
                $parent = dirname($dest);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                copy($src, $dest);
                $copied++;
            }
        }

        foreach (self::SEALED_BUILD_DIRS as $rel) {
            $src = "{$storage_src}/{$rel}";
            if (is_dir($src)) {
                $copied += $this->_copy_tree($src, "{$storage_dest}/{$rel}", []);
            }
        }

        return $copied;
    }

    /**
     * Create the empty, writable storage directories the runtime expects to exist on the
     * target (never populated from the dev box).
     *
     * @param string $storage_dest
     * @return void
     */
    private function _create_storage_skeleton(string $storage_dest): void
    {
        foreach (self::STORAGE_SKELETON as $rel) {
            $dir = "{$storage_dest}/{$rel}";
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Whether a root vendor/node_modules directory holds a real app-layer install (as
     * opposed to the empty autoloader/lock stub this template ships). Heuristic: it
     * contains at least one package subdirectory that is NOT one of the tool's own
     * bookkeeping dirs (composer's `composer`/`bin`, npm's `.bin`). The stub has only
     * those, so it correctly reads as "no real install" and is skipped.
     *
     * @param string $dir
     * @return bool
     */
    private function _has_real_install(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $bookkeeping = ['.', '..', 'composer', 'bin', '.bin'];
        foreach (scandir($dir) as $entry) {
            if (in_array($entry, $bookkeeping, true)) {
                continue;
            }
            if (is_dir("{$dir}/{$entry}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a deployable env template from the real .env (stripping framework-dev flags
     * and secrets, disabling debug), and create the system/.env symlink to ../.env. The
     * real .env is NEVER copied - the operator populates .env from .env.dist on the target.
     *
     * @param string $env_src Absolute path to the real (project-root) .env.
     * @param string $export_path
     * @return void
     */
    private function _generate_env_dist(string $env_src, string $export_path): void
    {
        if (!is_file($env_src)) {
            $this->warn('      .env not found - skipping .env.dist generation');

            return;
        }

        $lines = explode("\n", (string) file_get_contents($env_src));
        $drop_prefixes = [
            'IS_FRAMEWORK_DEVELOPER=', 'ENABLE_GET_TRACE=', 'FORCE_REBUILD_EVERY_REQUEST=',
            'MEMCACHED_HOST=', 'REDIS_HOST=', 'REDIS_PASSWORD=', 'REDIS_PORT=',
            'AWS_', 'PUSHER_', 'VITE_PUSHER_',
        ];

        $out = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            $drop = false;
            foreach ($drop_prefixes as $prefix) {
                if (str_starts_with($trimmed, $prefix)) {
                    $drop = true;
                    break;
                }
            }
            if ($drop) {
                continue;
            }

            if (str_starts_with($trimmed, 'CONSOLE_DEBUG_ENABLED=true')) {
                $line = 'CONSOLE_DEBUG_ENABLED=false';
            } elseif (str_starts_with($trimmed, 'APP_KEY=')) {
                $line = 'APP_KEY=';
            } elseif (str_starts_with($trimmed, 'LOG_LEVEL=debug')) {
                $line = 'LOG_LEVEL=info';
            }

            $out[] = $line;
        }

        file_put_contents_safe("{$export_path}/.env.dist", implode("\n", $out));

        // system/.env is a symlink to the project-root .env (framework invariant). The
        // operator creates the root .env from .env.dist; system/.env then resolves.
        $system_env = "{$export_path}/system/.env";
        if (!file_exists($system_env) && is_dir("{$export_path}/system")) {
            @symlink('../.env', $system_env);
        }
    }

    /**
     * Clear a directory recursively
     */
    private function _clear_directory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
