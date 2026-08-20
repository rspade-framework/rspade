<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Framework\Framework_Maintenance;
use Illuminate\Console\Command;

/**
 * rsx:framework:status - report the installed framework release and update
 * availability under the vendored-system topology (system/ is ordinary tracked
 * files, not a git submodule).
 *
 * Reports:
 *   - installed release: release_id + date from system/.rspade-release.json
 *     ("not an installed release" on a framework-development tree, which has no
 *     release manifest);
 *   - last update: the newest section of rsx/resource/framework_update_history.dat;
 *   - update availability: fetch the cached distribution clone (if present) and
 *     compare the installed commit against its tip, with a pending diffstat.
 */
class Framework_Status_Command extends Command
{
    protected $signature = 'rsx:framework:status';

    protected $description = 'Report the installed framework release and update availability';

    private const DEFAULT_UPSTREAM_URL = 'ssh://git@git.internal.hanson.xyz/brianhansonxyz/rspade_system.git';
    private const UPSTREAM_BRANCH = 'master';

    public function handle(): int
    {
        $this->line('');
        $this->info('=== RSpade Framework Status ===');
        $this->line('');

        $manifest_path = base_path('.rspade-release.json');
        $history_path = base_path('rsx/resource/framework_update_history.dat');
        // Tolerates absence throughout (reports "no cache" below): the distribution
        // clone is re-derivable and may simply not exist yet, or have been wiped with /tmp.
        $cache = Framework_Maintenance::upstream_cache_dir();

        // -------------------------------------------------------- installed release
        //
        // TWO DIFFERENT SHAs LIVE HERE AND THEY ARE NOT INTERCHANGEABLE:
        //
        //   $installed_commit - the DISTRIBUTION commit in rspade_system. This is the
        //                       identifier rsx:framework:pull, framework_update_history.dat
        //                       (`to=`) and the "Updates available" comparison below all
        //                       speak in. It IS the installed release.
        //   $installed_id     - .rspade-release.json's `release_id`: the MONOREPO commit
        //                       the release was BUILT FROM. A different repository's
        //                       namespace entirely.
        //
        // This block used to print $installed_id under the label "Installed release",
        // which meant `status` reported a sha that appears nowhere in the pull's own
        // vocabulary. During the 2026-08-11 Ascent diagnosis that cost a long detour
        // chasing a phantom version mismatch (status said fed581e02fa3; the recorded
        // release and the distribution tip were both 2f98e15dc53c - and both were right).
        // The distribution commit now leads; the source commit is labelled as such.
        $installed_id = null;
        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
            if (is_array($manifest)) {
                $installed_id = $manifest['release_id'] ?? null;
                $date = $manifest['date'] ?? '(unknown)';
                $count = is_array($manifest['files'] ?? null) ? count($manifest['files']) : 0;

                $installed_commit = $this->__resolve_installed_commit($history_path, $cache, $installed_id);

                $this->line('<fg=green>Installed release:</> '
                    . ($installed_commit !== null ? substr($installed_commit, 0, 12) : '(unresolved)'));
                $this->line('  Built from source commit: ' . ($installed_id ? substr($installed_id, 0, 12) : '(unknown)'));
                $this->line('  Published: ' . $date);
                $this->line('  Inventory: ' . $count . ' files');
            } else {
                $this->line('<fg=red>Installed release:</> release manifest is malformed');
                $installed_commit = null;
            }
        } else {
            $this->line('<fg=yellow>Not an installed release</> - this is a framework-development tree (no .rspade-release.json).');
            $this->line('');

            return 0;
        }

        $this->line('');

        // -------------------------------------------------------------- last update
        if (file_exists($history_path)) {
            $head = $this->__first_history_line($history_path);
            if ($head !== null) {
                $this->line('<fg=green>Last update:</> ' . $head);
            }
        } else {
            $this->line('<fg=yellow>No update history recorded yet.</>');
        }

        $this->line('');

        // ------------------------------------------------------- update availability
        if (!is_dir($cache)) {
            $this->line('<fg=yellow>[INFO]</> No distribution cache yet - run: php artisan rsx:framework:pull');
            $this->line('');

            return 0;
        }

        $fetch_out = [];
        $fetch_rc = 0;
        \exec_safe(
            'git --git-dir=' . escapeshellarg($cache) . ' fetch --prune ' . escapeshellarg(self::DEFAULT_UPSTREAM_URL)
            . ' ' . escapeshellarg('+refs/heads/*:refs/heads/*') . ' 2>&1',
            $fetch_out,
            $fetch_rc
        );
        if ($fetch_rc !== 0) {
            $this->line('<fg=yellow>[INFO]</> Could not reach the distribution repository (offline?); showing cached state.');
        }

        $tip_out = [];
        \exec_safe('git --git-dir=' . escapeshellarg($cache) . ' rev-parse ' . escapeshellarg('refs/heads/' . self::UPSTREAM_BRANCH) . ' 2>/dev/null', $tip_out);
        $tip = trim($tip_out[0] ?? '');

        if ($tip === '') {
            $this->line('<fg=red>[ERROR]</> Distribution cache has no ' . self::UPSTREAM_BRANCH . ' branch.');
            $this->line('');

            return 1;
        }

        if ($installed_commit !== null && $installed_commit === $tip) {
            $this->line('<fg=green>Up to date</> (' . substr($tip, 0, 12) . ')');
            $this->line('');

            return 0;
        }

        if ($installed_commit === null) {
            $this->line('<fg=yellow>Updates may be available.</> Installed commit could not be resolved; run rsx:framework:pull.');
            $this->line('  Distribution tip: ' . substr($tip, 0, 12));
            $this->line('');

            return 0;
        }

        $this->line('<fg=yellow>Updates available</>');
        $this->line('  Installed: ' . substr($installed_commit, 0, 12));
        $this->line('  Latest:    ' . substr($tip, 0, 12));
        $this->line('');

        $count_out = [];
        \exec_safe('git --git-dir=' . escapeshellarg($cache) . ' rev-list --count ' . escapeshellarg($installed_commit . '..' . $tip) . ' 2>/dev/null', $count_out);
        $pending = trim($count_out[0] ?? '');
        if ($pending !== '') {
            $this->line('  Pending commits: ' . $pending);
        }

        $stat_out = [];
        \exec_safe('git --git-dir=' . escapeshellarg($cache) . ' diff --stat ' . escapeshellarg($installed_commit) . ' ' . escapeshellarg($tip) . ' 2>/dev/null', $stat_out);
        if (!empty($stat_out)) {
            $this->line('');
            $this->line('  Files that will change:');
            foreach (array_slice($stat_out, 0, 15) as $line) {
                $this->line('    ' . $line);
            }
            if (count($stat_out) > 15) {
                $this->line('    ... and ' . (count($stat_out) - 15) . ' more');
            }
        }

        $this->line('');
        $this->line('To update: php artisan rsx:framework:pull');
        $this->line('');

        return 0;
    }

    /**
     * Resolve the installed rspade_system commit: primary = the newest history
     * section's to= field; secondary = match the installed release_id against the
     * cached distribution's .rspade-release.json history.
     */
    private function __resolve_installed_commit(string $history_path, string $cache, ?string $installed_id): ?string
    {
        // Primary: history to= field.
        if (file_exists($history_path)) {
            $head = $this->__first_history_line($history_path);
            if ($head !== null && preg_match('/\bto=([0-9a-f]{7,40})\b/', $head, $m)) {
                if (is_dir($cache)) {
                    $check = [];
                    $rc = 0;
                    \exec_safe('git --git-dir=' . escapeshellarg($cache) . ' cat-file -e ' . escapeshellarg($m[1] . '^{commit}') . ' 2>/dev/null', $check, $rc);
                    if ($rc === 0) {
                        return $m[1];
                    }
                } else {
                    return $m[1];
                }
            }
        }

        // Secondary: release_id scan of the cached distribution history.
        if ($installed_id !== null && is_dir($cache)) {
            $log = [];
            \exec_safe('git --git-dir=' . escapeshellarg($cache) . ' log --format=%H ' . self::UPSTREAM_BRANCH . ' -- .rspade-release.json 2>/dev/null', $log);
            foreach ($log as $sha) {
                $sha = trim($sha);
                if ($sha === '') {
                    continue;
                }
                $show = [];
                \exec_safe('git --git-dir=' . escapeshellarg($cache) . ' show ' . escapeshellarg($sha . ':.rspade-release.json') . ' 2>/dev/null', $show);
                $content = implode("\n", $show);
                if (preg_match('/"release_id"\s*:\s*"([^"]*)"/', $content, $m) && $m[1] === $installed_id) {
                    return $sha;
                }
            }
        }

        return null;
    }

    /**
     * The first machine-readable history header line (newest section), stripped of
     * the leading marker, or null when none is present.
     */
    private function __first_history_line(string $history_path): ?string
    {
        $fh = fopen($history_path, 'r');
        if ($fh === false) {
            return null;
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if (str_starts_with($line, '## RSPADE-UPDATE ')) {
                    return substr($line, strlen('## RSPADE-UPDATE '));
                }
            }
        } finally {
            fclose($fh);
        }

        return null;
    }
}
