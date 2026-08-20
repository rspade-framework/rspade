<?php

namespace App\RSpade\Core\Framework;

use App\RSpade\Core\Rsx;

/**
 * MAINTENANCE MODE: one flag file, two ways in.
 *
 *   - `php artisan rsx:maintenance:enable [--reason=<text>]` - the operator feature
 *     (bin/maintenance-mode.sh; also stops realtime, fpc-proxy, php-fpm and redis).
 *   - `rsx:framework:pull` - the same mechanism with the reason "framework update in progress",
 *     raised before owned_zone_sync and lifted in the exit trap (normal exit OR Ctrl-C).
 *
 * While the flag exists every WEB request returns 503, and the CLI gate is ALLOW-MOST-DENY-SOME:
 * it exists to keep AUTOMATED processes (cron tick, task workers, web) from firing into a
 * stopped environment, not to babysit humans. Blocked: rsx:task:process / rsx:task:worker.
 * Blocked unless --force: rsx:task:run. Everything else runs. Commands bearing OVERRIDE_FLAG
 * bypass the classification entirely (the pull's own sub-calls).
 *
 * The ENFORCEMENT is inline + autoload-free in system/public/index.php and system/artisan (a
 * half-synced vendor/ must still 503 cleanly, before Composer's autoloader loads). THIS class
 * is the in-app / test-facing API and the single source of truth for the path + override token;
 * the two pre-boot sites replicate FLAG_RELATIVE literally (they run before this can autoload)
 * and define the RSPADE_MAINT_MODE snapshot is_active() reads.
 *
 * The flag's content is TWO LINES: line 1 is the operator reason, quoted by every 503 / refusal
 * message; line 2 is "mode=<application mode>" stamped at raise time, which is the only way the
 * autoload-free web 503 can know whether it may print operator recovery advice. Both writers (this
 * class and bin/maintenance-mode.sh) produce that shape; readers take line 1 for the reason and
 * treat a missing stamp as production.
 *
 * The flag lives under storage/rsx-framework/ - the framework-update state directory (shared with
 * the mutation-marker store, see Framework_Mutations), which the tamper gate skips
 * (Framework_Verify) and rsx:clean preserves - so it can never itself trip a verify finding and it
 * survives the window until deliberately cleared.
 */
class Framework_Maintenance
{
    /** Path RELATIVE to the PROJECT root (the directory containing storage/). The pre-boot enforcement sites hardcode this literally. */
    public const FLAG_RELATIVE = 'storage/rsx-framework/.maintenance.mode.framework.update';

    /**
     * The internal override token the pull passes on its own artisan calls so THEY run while the
     * gate is up. Hidden: it is argv-scanned + stripped pre-boot, never registered as an
     * InputOption, so it appears in no `php artisan` help/list output.
     */
    public const OVERRIDE_FLAG = '--_framework-update-override';

    /** Prefix of the flag's SECOND line - the application mode at raise time. See stamped_mode(). */
    public const MODE_PREFIX = 'mode=';

    /**
     * Absolute path to the flag file. Defaults to the RESOLVED storage root - volatile
     * storage relocated out of system/ to <project>/storage, so it must never be derived
     * from base_path() again. $base is the directory CONTAINING storage/ and exists for
     * pre-boot / synthetic-tree callers (base_path() is unavailable before boot).
     */
    public static function flag_path(?string $base = null): string
    {
        if ($base !== null) {
            return rtrim($base, '/') . '/' . self::FLAG_RELATIVE;
        }

        return storage_path('rsx-framework/.maintenance.mode.framework.update');
    }

    /**
     * The cached bare clone of the rspade_system distribution repository - the SINGLE
     * source of truth for its location (rsx:framework:status, rsx:framework:verify's
     * pristine provider, and the pull script's CACHE all resolve here; the shell copy
     * carries the same literal with a comment pointing back at this method).
     *
     * It lives in /tmp, NOT under storage/: it is pure re-derivable data (the updater
     * creates it when absent and fetch-updates it when present), so keeping a ~100MB
     * git clone out of every storage backup/export is free. EVERY consumer must
     * tolerate its absence - losing it costs exactly one re-clone.
     */
    public static function upstream_cache_dir(): string
    {
        return '/tmp/rspade_upstream.git';
    }

    /**
     * Test seam: force is_active() to a fixed answer for the current process, so a PHP test can
     * exercise maintenance behavior (cache tolerance, lock backend selection) without raising
     * the real flag and 503-ing the box. null = not forced.
     *
     * @var bool|null
     */
    public static $force_active_for_tests = null;

    /**
     * THE runtime choke point: "is this process running under maintenance mode?".
     *
     * With no $base override it answers from RSPADE_MAINT_MODE - the per-process SNAPSHOT both
     * entrypoints (system/artisan, system/public/index.php) define from ONE filesystem stat at
     * boot. Snapshotting matters: a consumer (the lock backend above all) must not observe the
     * flag flipping mid-process. An exotic entrypoint that defined no constant re-stats the file.
     *
     * $base (the synthetic-tree / pre-boot test seam) always stats, never consults the constant.
     */
    public static function is_active(?string $base = null): bool
    {
        if ($base !== null) {
            return file_exists(self::flag_path($base));
        }

        if (self::$force_active_for_tests !== null) {
            return self::$force_active_for_tests;
        }

        if (defined('RSPADE_MAINT_MODE')) {
            return (bool) constant('RSPADE_MAINT_MODE');
        }

        return file_exists(self::flag_path());
    }

    /**
     * The flag as it is ON DISK RIGHT NOW, ignoring the boot snapshot.
     *
     * The straggler check: a process that booted BEFORE maintenance was enabled carries
     * RSPADE_MAINT_MODE = false, so its Redis calls fail on a stopped server with no tolerance
     * engaged. Every tolerance therefore re-checks HERE on the connect-failure path only (one
     * extra stat, and only when something already went wrong). A connect failure with no flag on
     * disk stays exactly as loud as it has always been.
     */
    public static function is_active_on_disk(?string $base = null): bool
    {
        if (self::$force_active_for_tests !== null) {
            return self::$force_active_for_tests;
        }

        return file_exists(self::flag_path($base));
    }

    /**
     * The operator-facing reason maintenance mode is up - the flag file's CONTENT, written by
     * whoever raised it (bin/maintenance-mode.sh writes --reason, the pull writes "framework
     * update in progress"). Every "blocked by maintenance mode" message quotes it.
     */
    /**
     * The operator reason: LINE 1 of the flag, and nothing else.
     *
     * The flag carries a second line (see raise()), so this must not return the raw file
     * or every 503 body and CLI refusal would start printing "mode=development" after the
     * reason.
     */
    public static function reason(?string $base = null): string
    {
        $reason = trim(self::__flag_line((string) @file_get_contents(self::flag_path($base)), 0));

        return $reason !== '' ? $reason : 'maintenance mode';
    }

    /**
     * The application mode STAMPED INTO THE FLAG when the window was raised, or null when
     * the flag carries no stamp.
     *
     * WHY IT IS STAMPED RATHER THAN READ. The web 503 is emitted in public/index.php
     * BEFORE composer autoload - deliberately, so a half-synced vendor/ still answers
     * cleanly - which means the reader cannot boot config or call this class at all. The
     * WRITER always knows the mode, so it records it once instead of every request paying
     * to rediscover it. It is also stale-flag-safe: the stamp describes the mode at raise
     * time, which cannot have changed inside the window.
     *
     * A null return (an older flag, or one written by hand) is treated as production
     * everywhere it matters: absence resolves to the direction that discloses nothing.
     */
    public static function stamped_mode(?string $base = null): ?string
    {
        $line = trim(self::__flag_line((string) @file_get_contents(self::flag_path($base)), 1));
        if (!str_starts_with($line, self::MODE_PREFIX)) {
            return null;
        }

        $mode = trim(substr($line, strlen(self::MODE_PREFIX)));

        return $mode !== '' ? $mode : null;
    }

    public static function raise(?string $base = null, string $reason = 'framework update in progress'): void
    {
        $path = self::flag_path($base);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'maintenance mode';
        }

        // LINE 1 = reason, LINE 2 = mode stamp. The shell writer
        // (bin/maintenance-mode.sh) produces the identical two-line shape; this format is
        // the contract between them and the autoload-free readers.
        file_put_contents($path, $reason . "\n" . self::MODE_PREFIX . Rsx::get_mode() . "\n");
    }

    /**
     * One line of the flag's content, 0-indexed. Kept private so the two-line format has
     * exactly one parser on the PHP side.
     */
    private static function __flag_line(string $contents, int $index): string
    {
        $lines = preg_split('/\R/', $contents);

        return $lines[$index] ?? '';
    }

    public static function clear(?string $base = null): void
    {
        $path = self::flag_path($base);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
