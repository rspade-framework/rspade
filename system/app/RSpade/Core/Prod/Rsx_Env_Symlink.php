<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * @REALPATH-EXCEPTION - This class MANAGES the system/.env symlink: it must
 * deliberately FOLLOW symlinks to compare resolved targets (rsxrealpath, which
 * does not follow symlinks, would defeat the healer's core comparison).
 */

namespace App\RSpade\Core\Prod;

use RuntimeException;

/**
 * Maintains the ".env symlink invariant".
 *
 * The intended layout is: base_path('.env') (system/.env) is a SYMLINK to the
 * project-root .env (dirname(base_path()).'/.env'; httpdocs/.env in deployed
 * layouts). Laravel/Dotenv loads base_path('.env'); when that path is a symlink
 * to the root file there is exactly ONE authoritative .env and edits to either
 * path are the same edit.
 *
 * Deploys and clones frequently materialize the symlink into a real FILE. After
 * that, the two files drift: Laravel reads only system/.env, so edits to the
 * root .env become inert (an entire realtime/OneDrive config block once sat
 * unread this way). A naive re-symlink would silently DISCARD keys that live only
 * in system/.env (RSX_MODE historically lived there).
 *
 * heal() restores the invariant safely:
 *   - the ROOT .env is authoritative; on a merge conflict the ROOT value WINS and
 *     the discarded system/.env value is REPORTED (never silently dropped);
 *   - keys unique to system/.env are APPENDED to the root .env under a dated
 *     marker comment;
 *   - system/.env is then replaced by a RELATIVE symlink (../.env) so it is
 *     portable across layouts and mount moves.
 *
 * This class is the reason Rsx_Prod_Env's single-file base_path('.env') read/write
 * is correct by construction: once healed, that one path IS the root file.
 */
class Rsx_Env_Symlink
{
    /**
     * Suffix for the safety copy written before a real system/.env file is
     * replaced. Contains secrets - kept 0600 and outside any web-servable root.
     */
    public const BACKUP_SUFFIX = '.replaced_by_healer';

    /**
     * Test seam: override the system/.env path (defaults to base_path('.env')).
     */
    protected static ?string $_system_env_override = null;

    /**
     * Test seam: override the root .env path (defaults to dirname(base_path())/.env).
     */
    protected static ?string $_root_env_override = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Restore the .env symlink invariant, mutating the filesystem as required.
     *
     * @return array {
     *   status: already_healthy|healed|created,
     *   merged_keys: string[],
     *   overridden_keys: array<array{key:string,system_value:string,root_value:string}>,
     *   actions: string[],
     *   backup_path: ?string
     * }
     */
    public static function heal(): array
    {
        $plan = self::__plan();
        $state = $plan['state'];
        $system_env = $plan['system_env'];
        $root_env = $plan['root_env'];

        $report = [
            'status' => 'already_healthy',
            'merged_keys' => [],
            'overridden_keys' => [],
            'actions' => [],
            'backup_path' => null,
        ];

        if ($state === 'already_healthy') {
            return $report;
        }

        if ($state === 'both_missing') {
            throw new RuntimeException(
                'env healer: neither ' . $root_env . ' nor ' . $system_env
                . ' exists - the application is unbootable. The healer maintains the'
                . ' .env symlink invariant; it does not create an initial .env.'
            );
        }

        if ($state === 'wrong_symlink') {
            $target = self::__relative_link_target($system_env, $root_env);
            self::__install_symlink($system_env, $target);
            $report['status'] = 'healed';
            $report['actions'][] = 'Repointed system/.env symlink to ' . $target
                . ' (was -> ' . $plan['current_link_target'] . ').';

            return $report;
        }

        if ($state === 'system_missing') {
            $target = self::__relative_link_target($system_env, $root_env);
            self::__install_symlink($system_env, $target);
            $report['status'] = 'healed';
            $report['actions'][] = 'Created system/.env symlink -> ' . $target
                . ' (root .env is authoritative).';

            return $report;
        }

        if ($state === 'root_missing') {
            // Root .env absent: MOVE the system/.env content to the root, then symlink.
            $report['backup_path'] = self::__backup($system_env);
            self::__atomic_write($root_env, file_get_contents($system_env));
            $target = self::__relative_link_target($system_env, $root_env);
            self::__install_symlink($system_env, $target);
            $report['status'] = 'created';
            $report['actions'][] = 'Root .env was missing: moved system/.env content to ' . $root_env . '.';
            $report['actions'][] = 'Replaced system/.env with symlink -> ' . $target . '.';

            return $report;
        }

        // state === 'regular_file': merge unique keys into the root (root wins),
        // then replace system/.env with the symlink.
        $report['merged_keys'] = $plan['merged_keys'];
        $report['overridden_keys'] = $plan['overridden_keys'];
        $report['backup_path'] = self::__backup($system_env);

        if (!empty($plan['merged_lines'])) {
            self::__append_block($root_env, $plan['merged_lines']);
            $report['actions'][] = 'Appended ' . count($plan['merged_lines'])
                . ' key(s) unique to system/.env into ' . $root_env . '.';
        }
        if (!empty($plan['overridden_keys'])) {
            $report['actions'][] = count($plan['overridden_keys'])
                . ' conflicting key(s) kept the root value; system values discarded (see overridden_keys).';
        }

        $target = self::__relative_link_target($system_env, $root_env);
        self::__install_symlink($system_env, $target);
        $report['actions'][] = 'Replaced system/.env with symlink -> ' . $target . '.';
        $report['status'] = 'healed';

        return $report;
    }

    /**
     * Detect the current state WITHOUT mutating anything (drift / doctor surface).
     *
     * @return array {
     *   healthy: bool,
     *   state: string,
     *   status: string,          # what heal() WOULD produce
     *   merged_keys: string[],
     *   overridden_keys: array,
     *   actions: string[],       # planned actions, phrased as "would ..."
     *   system_env: string,
     *   root_env: string
     * }
     */
    public static function get_drift_report(): array
    {
        $plan = self::__plan();
        $state = $plan['state'];

        $report = [
            'healthy' => $state === 'already_healthy',
            'state' => $state,
            'status' => null,
            'merged_keys' => $plan['merged_keys'],
            'overridden_keys' => $plan['overridden_keys'],
            'actions' => [],
            'system_env' => $plan['system_env'],
            'root_env' => $plan['root_env'],
        ];

        if ($state === 'already_healthy') {
            $report['status'] = 'already_healthy';
            $report['actions'][] = 'system/.env is already a symlink to the root .env; no change needed.';
        } elseif ($state === 'wrong_symlink') {
            $report['status'] = 'healed';
            $report['actions'][] = 'Would repoint system/.env symlink (currently -> '
                . $plan['current_link_target'] . ') to the root .env.';
        } elseif ($state === 'system_missing') {
            $report['status'] = 'healed';
            $report['actions'][] = 'Would create system/.env as a symlink to the root .env.';
        } elseif ($state === 'regular_file') {
            $report['status'] = 'healed';
            if (!empty($plan['merged_keys'])) {
                $report['actions'][] = 'Would append ' . count($plan['merged_keys'])
                    . ' key(s) unique to system/.env into the root .env.';
            }
            if (!empty($plan['overridden_keys'])) {
                $report['actions'][] = 'Would keep the root value for '
                    . count($plan['overridden_keys']) . ' conflicting key(s) (system values discarded).';
            }
            $report['actions'][] = 'Would back up system/.env and replace it with a symlink to the root .env.';
        } elseif ($state === 'root_missing') {
            $report['status'] = 'created';
            $report['actions'][] = 'Root .env is missing: would move system/.env content to the root, then symlink.';
        } else {
            // both_missing
            $report['status'] = 'error';
            $report['actions'][] = 'Neither .env exists - the healer cannot proceed (unbootable app).';
        }

        return $report;
    }

    /**
     * rsx:health probe: is the system/.env -> root .env symlink invariant intact? A public
     * static `#[Health_Check('label')]` (bare marker attribute - never a defined class),
     * read-only (get_drift_report never mutates). A drifted state is a WARN (the app still
     * boots, but edits to the root .env may be inert) with a heal-command remediation.
     *
     * @return array
     */
    #[Health_Check('Env Symlink')]
    public static function env_symlink(): array
    {
        $report = self::get_drift_report();

        if ($report['healthy']) {
            return ['status' => 'OK', 'detail' => 'system/.env is a symlink to the root .env'];
        }

        return [
            'status' => 'WARN',
            'detail' => 'symlink invariant drifted (state: ' . $report['state'] . ')',
            'remediation' => 'run php artisan rsx:env:heal',
        ];
    }

    // -------------------------------------------------------------------------
    // Planning (pure detection - no mutation)
    // -------------------------------------------------------------------------

    /**
     * Resolve paths, classify the current state, and (for the merge/move states)
     * precompute exactly what would change. Never mutates the filesystem.
     */
    protected static function __plan(): array
    {
        $system_env = self::__system_env_path();
        $root_env = self::__root_env_path();

        $plan = [
            'system_env' => $system_env,
            'root_env' => $root_env,
            'state' => null,
            'merged_keys' => [],
            'overridden_keys' => [],
            'merged_lines' => [],
            'current_link_target' => null,
        ];

        if (is_link($system_env)) {
            $plan['current_link_target'] = readlink($system_env);
            $resolved_system = realpath($system_env);
            $resolved_root = realpath($root_env);
            $healthy = $resolved_system !== false
                && $resolved_root !== false
                && $resolved_system === $resolved_root;
            $plan['state'] = $healthy ? 'already_healthy' : 'wrong_symlink';

            return $plan;
        }

        if (is_file($system_env)) {
            if (file_exists($root_env)) {
                $plan['state'] = 'regular_file';
                self::__plan_merge($plan, $system_env, $root_env);

                return $plan;
            }

            $plan['state'] = 'root_missing';

            return $plan;
        }

        // system/.env does not exist at all.
        if (file_exists($root_env)) {
            $plan['state'] = 'system_missing';

            return $plan;
        }

        $plan['state'] = 'both_missing';

        return $plan;
    }

    /**
     * Fill in merged_keys / merged_lines / overridden_keys for the regular-file
     * merge: keys unique to system/.env are moved; keys in both keep the root
     * value (root wins) and the discarded system value is recorded.
     */
    protected static function __plan_merge(array &$plan, string $system_env, string $root_env): void
    {
        $system_data = self::__parse_env_lines(file_get_contents($system_env));
        $root_data = self::__parse_env_lines(file_get_contents($root_env));

        foreach ($system_data['keys'] as $key => $system_value) {
            $in_root = array_key_exists($key, $root_data['keys']);
            if (!$in_root) {
                $plan['merged_keys'][] = $key;
                $plan['merged_lines'][] = $system_data['lines'][$key];
                continue;
            }

            $root_value = $root_data['keys'][$key];
            if (trim($system_value) !== trim($root_value)) {
                $plan['overridden_keys'][] = [
                    'key' => $key,
                    'system_value' => trim($system_value),
                    'root_value' => trim($root_value),
                ];
            }
        }
    }

    // -------------------------------------------------------------------------
    // Parsing
    // -------------------------------------------------------------------------

    /**
     * Parse KEY=VALUE lines. Comments and blank lines are ignored. Quoted values
     * are preserved verbatim - we move whole lines, never reformat a value.
     *
     * @return array{keys: array<string,string>, lines: array<string,string>}
     */
    protected static function __parse_env_lines(string $contents): array
    {
        $keys = [];
        $lines = [];

        foreach (explode("\n", $contents) as $raw) {
            $trimmed = ltrim($raw);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            $eq_pos = strpos($raw, '=');
            if ($eq_pos === false) {
                continue;
            }

            $key = trim(substr($raw, 0, $eq_pos));
            $is_valid_key = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key);
            if (!$is_valid_key) {
                continue;
            }

            $keys[$key] = substr($raw, $eq_pos + 1);
            $lines[$key] = rtrim($raw, "\r\n");
        }

        return ['keys' => $keys, 'lines' => $lines];
    }

    // -------------------------------------------------------------------------
    // Mutation primitives
    // -------------------------------------------------------------------------

    /**
     * Append the unique-key lines to the root .env under a dated marker comment.
     * The root file's existing bytes are left untouched (root wins). Written via
     * temp+rename in the file's own directory for an atomic replace.
     */
    protected static function __append_block(string $root_env, array $merged_lines): void
    {
        $root_contents = file_get_contents($root_env);
        if ($root_contents !== '' && !str_ends_with($root_contents, "\n")) {
            $root_contents .= "\n";
        }

        $marker = '# merged from system/.env by rsx env healer (' . date('Y-m-d') . ')';
        $block = "\n" . $marker . "\n" . implode("\n", $merged_lines) . "\n";

        self::__atomic_write($root_env, $root_contents . $block);
    }

    /**
     * Atomic file write: stage into a sibling temp file, then rename over target.
     *
     * Deliberately NOT file_put_contents_safe(): the .env files never live under
     * the sealed build root (so the seal guard is irrelevant), and staging in the
     * target's own directory guarantees a same-filesystem rename.
     */
    protected static function __atomic_write(string $path, string $contents): void
    {
        $tmp = $path . '.healer_tmp_' . getmypid();

        if (file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException('env healer: failed to stage write for ' . $path);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('env healer: failed to move staged write into place at ' . $path);
        }
    }

    /**
     * Install a symlink at $link_path pointing at $relative_target, atomically
     * replacing whatever (regular file / wrong symlink / nothing) is there now.
     */
    protected static function __install_symlink(string $link_path, string $relative_target): void
    {
        $tmp = $link_path . '.healer_link_' . getmypid();

        if (is_link($tmp) || file_exists($tmp)) {
            @unlink($tmp);
        }

        if (!symlink($relative_target, $tmp)) {
            throw new RuntimeException('env healer: failed to create symlink at ' . $link_path);
        }

        if (!rename($tmp, $link_path)) {
            @unlink($tmp);
            throw new RuntimeException('env healer: failed to move symlink into place at ' . $link_path);
        }
    }

    /**
     * Copy a real system/.env file to a 0600 backup before it is replaced.
     *
     * @return string The backup path.
     */
    protected static function __backup(string $system_env): string
    {
        $backup = $system_env . self::BACKUP_SUFFIX;

        if (!copy($system_env, $backup)) {
            throw new RuntimeException('env healer: failed to back up ' . $system_env);
        }

        chmod($backup, 0600);

        return $backup;
    }

    // -------------------------------------------------------------------------
    // Path helpers
    // -------------------------------------------------------------------------

    /**
     * The system/.env path (the one Laravel/Dotenv actually loads).
     */
    protected static function __system_env_path(): string
    {
        return self::$_system_env_override ?? base_path('.env');
    }

    /**
     * The project-root .env path (one level above base_path()).
     */
    protected static function __root_env_path(): string
    {
        return self::$_root_env_override ?? dirname(base_path()) . '/.env';
    }

    /**
     * Compute a RELATIVE symlink target from the link's own directory to the root
     * .env. Layout-agnostic: for the standard layout this yields "../.env".
     */
    protected static function __relative_link_target(string $link_path, string $target_path): string
    {
        $from = explode('/', trim(dirname($link_path), '/'));
        $to = explode('/', trim($target_path, '/'));

        $common = 0;
        $from_count = count($from);
        $to_count = count($to);
        while ($common < $from_count && $common < $to_count && $from[$common] === $to[$common]) {
            $common++;
        }

        $up = array_fill(0, $from_count - $common, '..');
        $down = array_slice($to, $common);
        $parts = array_merge($up, $down);

        return empty($parts) ? '.' : implode('/', $parts);
    }

    // -------------------------------------------------------------------------
    // Test seams
    // -------------------------------------------------------------------------

    /**
     * Point the healer at throwaway paths (tests only). Pass null to restore a
     * single side to its real default.
     */
    public static function _testing_set_paths(?string $system_env, ?string $root_env): void
    {
        self::$_system_env_override = $system_env;
        self::$_root_env_override = $root_env;
    }

    /**
     * Restore both path seams to their real defaults (tests only).
     */
    public static function _testing_reset(): void
    {
        self::$_system_env_override = null;
        self::$_root_env_override = null;
    }
}
