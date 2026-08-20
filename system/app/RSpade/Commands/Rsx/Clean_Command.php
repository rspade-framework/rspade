<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Exception;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Clean_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:clean {--silent : Suppress all output except errors} {--force : Skip the system/ integrity check before the downstream reset (discard framework-tree drift unconditionally)}';

    /**
     * Framework-internal flag (the `--_` convention - argv-stripped pre-boot, declared as no
     * InputOption, so it appears in no help output): clear caches only, skipping the
     * downstream system/ reset and its integrity check. EVERY caller is programmatic - the
     * updater and the build tooling that shells to rsx:clean want cache invalidation, never
     * git-state changes. A human (or the pre-commit hook) runs plain rsx:clean and gets the
     * reset, which is the point of the reset.
     */
    public const FLAG_NO_SYSTEM_RESET = '--_no-system-reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean RSX caches, build artifacts, and temp files';

    /**
     * Set when the downstream system/ reset was REFUSED by the integrity gate
     * (unauthorized framework modifications found). Carries rsx:framework:verify's
     * output for the final error report; handle() exits 1 when non-null so callers
     * (the pre-commit hook, scripts) get a capturable failure.
     *
     * @var string|null
     */
    protected $reset_refusal = null;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Prevent being called via $this->call() - must use passthru for fresh process
        $this->prevent_call_from_another_command();

        // Refuse to wipe a sealed prod build. rsx-build is an immutable deployment
        // artifact while sealed - clearing it would leave the app unable to serve.
        if (\App\RSpade\Core\Prod\Rsx_Prod_Seal::is_sealed()) {
            $this->error('System is in prod mode (sealed build).');
            $this->line('  Run rsx:prod:refresh to rebuild the assets, or rsx:prod:disable first.');

            return 1;
        }

        $silent = $this->option('silent');

        // Restore the .env symlink invariant before anything else. A drifted
        // system/.env (materialized into a real file by a deploy/clone) would make
        // config edits inert; heal it here on the normal (unsealed) clean path.
        $env_report = \App\RSpade\Core\Prod\Rsx_Env_Symlink::heal();
        if (!$silent && $env_report['status'] !== 'already_healthy') {
            $this->line('  .env symlink invariant restored (status: ' . $env_report['status'] . ').');
            foreach ($env_report['overridden_keys'] as $row) {
                $this->line('    ! ' . $row['key'] . ': kept root value, discarded system value.');
            }
            if (!empty($env_report['backup_path'])) {
                $this->line('    Backup: ' . $env_report['backup_path'] . ' (0600)');
            }
            $this->newLine();
        }

        // Show warning if not in framework developer mode
        $is_framework_dev = config('rsx.code_quality.is_framework_developer', false);
        if (!$is_framework_dev && !$silent) {
            $this->warn('[WARNING]  Running rsx:clean is rarely necessary');
            $this->line('');
            $this->line('   The manifest system automatically detects and rebuilds when files change.');
            $this->line('   Manual cache clearing only adds 30-60 seconds to the next request.');
            $this->line('');
            $this->line('   Only run this command if:');
            $this->line('   - Catastrophic errors require a fresh start');
            $this->line('   - Framework code itself was updated outside normal workflows');
            $this->line('   - Explicitly instructed by framework documentation');
            $this->line('');
            $this->line('   Consider removing rsx:clean from your development workflow.');
            $this->newLine();
        }

        if (!$silent) {
            $this->info('Cleaning RSX caches...');
            $this->newLine();
        }

        $cleaned_items = [];

        // 1. Clear rsx-build directory recursively - EVERYTHING
        $build_path = storage_path('rsx-build');
        if (is_dir($build_path)) {
            $this->clear_directory($build_path);
            $cleaned_items[] = '[OK] Build storage cleaned';
        }

        // 2. Clear rsx-tmp directory recursively - EVERYTHING
        //
        //    REAP BEFORE THE WIPE. Every node RPC helper lives on a unix socket in this
        //    directory, and each one took that path as an argv argument at spawn time and
        //    never reconsiders it. Unlinking the sockets under a running daemon strands it
        //    permanently: it keeps serving an inode nobody can reach again, and no socket
        //    message - not even a "force stop" - can ever reach it. That is what produced
        //    the ten-deep orphan pile this command was quietly creating, and it violated
        //    the framework's own rule in bin/CLAUDE.md (any operation that changes a socket
        //    or state directory must reap the helpers bound to the previous one).
        //
        //    Killing them costs nothing: they hold no state and the next process that needs
        //    one spawns it on demand. Doing it BEFORE the unlink also lets each daemon's own
        //    SIGTERM handler remove its socket file on the way out.
        $quiesced = \App\RSpade\Core\JsParsers\Rpc_Client_Abstract::quiesce_all();
        if ($quiesced > 0) {
            $cleaned_items[] = '[OK] RPC helper daemons quiesced (' . $quiesced . ')';
        }

        $tmp_path = storage_path('rsx-tmp');
        if (is_dir($tmp_path)) {
            $this->clear_directory($tmp_path);
            $cleaned_items[] = '[OK] Temp storage cleaned';
        }

        // 3. Sweep orphaned cross-filesystem staging directories (.tmp_<n>)
        //    left behind by file_put_contents_safe() if a process died mid-write.
        $roots = array_unique(array_filter([
            base_path(),
            realpath(base_path('rsx')) ?: null,
        ]));
        $orphan_count = $this->remove_orphan_temp_dirs($roots);
        if ($orphan_count > 0) {
            $cleaned_items[] = "[OK] Removed {$orphan_count} orphaned temp director" . ($orphan_count === 1 ? 'y' : 'ies');
        }

        // 4. Clear Redis cache directly without loading framework
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $redis->flushdb();
            $cleaned_items[] = '[OK] Redis cache cleared';
        } catch (Exception $e) {
            $cleaned_items[] = '[WARNING] Redis cache skipped (not configured or unreachable)';
        }

        // Note: We never clear rsx-locks directory as it contains active lock files

        // 5. Downstream apps only: reset system/ to its last committed state.
        //    system/ is the vendored framework tree - ordinary tracked files OWNED by the
        //    framework updater (rsx:framework:pull), which is the only thing that may commit
        //    it. "Clean" for an app developer therefore includes discarding any local drift
        //    in that tree: unstage system/ and restore its tracked files to HEAD. Untracked
        //    files are left alone (they may be legitimate runtime artifacts).
        //    The framework monorepo is EXEMPT: there system/ IS the work being done.
        //    --_no-system-reset skips this step entirely (caches only): build tooling that
        //    shells to rsx:clean (rsx:manifest:build, rsx:bundle:compile, the updater, ...)
        //    wants cache invalidation, never git-state changes - the reset is for a HUMAN
        //    (or agent) running rsx:clean directly, and for the pre-commit hook.
        if (!$is_framework_dev && !\App\RSpade\Core\Console\Rsx_Internal_Flags::has(self::FLAG_NO_SYSTEM_RESET)) {
            $note = $this->reset_system_tree();
            if ($note !== null) {
                $cleaned_items[] = $note;
            }
        }

        // Display results
        if (!$silent) {
            if (empty($cleaned_items)) {
                $this->info('Nothing to clean - all caches already empty');
            } else {
                foreach ($cleaned_items as $item) {
                    $this->line("  $item");
                }
                $this->newLine();
                $this->info('[OK] RSX caches cleaned successfully');
            }
        }

        // A refused system/ reset is an ERROR even under --silent: the caches above
        // were still cleaned, but the framework tree carries unauthorized (hand-made)
        // modifications that a reset would silently destroy. Exit 1 so callers can react.
        if ($this->reset_refusal !== null) {
            $this->error('[ERROR] system/ reset refused - unauthorized framework modifications detected:');
            $this->line($this->reset_refusal);
            $this->line('  Inspect the changes:  php artisan rsx:framework:pull --diff-system-changes');
            $this->line('  Discard them anyway:  php artisan rsx:clean --force');
            $this->line('                   or:  git checkout HEAD -- system   (from the project root)');

            return 1;
        }

        return 0;
    }

    /**
     * Reset the vendored framework tree (system/) to its last committed state.
     *
     * Downstream apps only (the caller gates on is_framework_developer). Runs
     * `git reset -- system` + `git checkout -- system` + `git clean -fd -- system`
     * from the PROJECT ROOT: unstages system/, restores its tracked files to HEAD,
     * and REMOVES untracked files (owner ruling 2026-08-12 - on a downstream box the
     * framework tree is machine-written, so an untracked file there is churn or
     * foreign by definition; a stale class-override .php.upstream leftover was
     * surviving even --force). Gitignored files (the system/.env symlink, local
     * caches) are deliberately untouched - no -x. Active class-override churn is
     * regenerate-on-demand state: the next manifest build re-applies any rename it
     * needs.
     *
     * Git environment variables are stripped from the subprocesses: rsx:clean is
     * invoked from inside the pre-commit hook, where git exports GIT_DIR /
     * GIT_INDEX_FILE and would otherwise re-target these calls at the in-progress
     * commit's index.
     *
     * @return string|null Line for the cleaned-items report, or null when there is
     *                     nothing to say.
     */
    protected function reset_system_tree()
    {
        $skip_reason = $this->framework_update_in_progress();
        if ($skip_reason !== null) {
            return '[WARNING] system/ reset skipped (' . $skip_reason . ')';
        }

        $project_root = dirname(base_path());

        // env -u strips the inherited git context (see docblock); every call is
        // explicitly -C'd at the project root.
        $git = 'env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C ' . escapeshellarg($project_root);

        $output = [];
        $rc = 0;
        exec_safe($git . ' rev-parse --git-dir', $output, $rc);
        if ($rc !== 0) {
            return '[WARNING] system/ reset skipped (' . $project_root . ' is not a git repository)';
        }

        exec_safe($git . ' ls-files -- system | head -n 1', $output, $rc);
        if ($rc !== 0 || empty(array_filter($output))) {
            return '[WARNING] system/ reset skipped (no tracked files under system/)';
        }

        // Integrity gate: before discarding drift, prove the framework tree carries
        // no UNAUTHORIZED (hand-made) modifications. rsx:framework:verify exits
        // non-zero iff it finds any; class-override churn, marked framework churn,
        // and a missing release manifest all pass. A refusal makes rsx:clean itself
        // exit 1 (see handle()) so callers can react instead of losing work to a
        // silent reset. --force skips the check: rsx:framework:pull runs its own
        // tamper gate before calling us, and a human passing --force is explicitly
        // choosing to discard whatever is there. Framework-developer trees never
        // reach this method (the caller gates on is_framework_developer).
        if (!$this->option('force')) {
            $verify_rc = \Illuminate\Support\Facades\Artisan::call('rsx:framework:verify');
            if ($verify_rc !== 0) {
                $this->reset_refusal = trim(\Illuminate\Support\Facades\Artisan::output());

                return '[ERROR] system/ reset refused (unauthorized framework modifications)';
            }
        }

        exec_safe($git . ' reset -q -- system', $output, $rc);
        if ($rc !== 0) {
            return '[WARNING] system/ reset skipped (git reset failed: ' . trim(implode(' ', $output)) . ')';
        }

        exec_safe($git . ' checkout -q -- system', $output, $rc);
        if ($rc !== 0) {
            return '[WARNING] system/ reset incomplete (git checkout failed: ' . trim(implode(' ', $output)) . ')';
        }

        // Untracked files too (see docblock). -q: unix silence; failures speak below.
        exec_safe($git . ' clean -qfd -- system', $output, $rc);
        if ($rc !== 0) {
            return '[WARNING] system/ reset incomplete (git clean failed: ' . trim(implode(' ', $output)) . ')';
        }

        return '[OK] system/ reset to last commit';
    }

    /**
     * Detect a framework update in flight, in which case system/ must NOT be reset.
     *
     * rsx:framework:pull runs `rsx:clean` as part of its rebuild, AFTER it has synced the
     * new framework tree but BEFORE it commits it. Resetting system/ at that moment would
     * silently revert the whole update to the previous release. The updater cannot be
     * relied on to announce itself (an older downstream updater ships no such signal), so
     * detection is ambient and layered:
     *
     *   1. RSPADE_FRAMEWORK_COMMIT / RSPADE_FRAMEWORK_UPDATE in the environment.
     *   2. The framework-update maintenance flag is raised.
     *   3. An ancestor process is the updater script itself (Linux /proc walk).
     *
     * @return string|null Reason string when an update is in flight, null otherwise.
     */
    protected function framework_update_in_progress()
    {
        if (getenv('RSPADE_FRAMEWORK_COMMIT') === '1' || getenv('RSPADE_FRAMEWORK_UPDATE') === '1') {
            return 'framework update in progress';
        }

        if (\App\RSpade\Core\Framework\Framework_Maintenance::is_active()) {
            return 'framework update maintenance window is up';
        }

        if ($this->has_updater_ancestor()) {
            return 'running inside rsx:framework:pull';
        }

        return null;
    }

    /**
     * Walk the process ancestry looking for the framework updater script.
     *
     * Linux-only (/proc); returns false where /proc is unavailable, in which case the
     * other signals in framework_update_in_progress() carry the detection.
     *
     * @return bool
     */
    protected function has_updater_ancestor()
    {
        if (!is_dir('/proc')) {
            return false;
        }

        $pid = getmypid();
        for ($depth = 0; $depth < 12 && $pid > 1; $depth++) {
            $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
            if ($cmdline !== false && str_contains($cmdline, 'framework-pull-upstream')) {
                return true;
            }

            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat === false) {
                return false;
            }

            // Field 2 (comm) is parenthesized and may contain spaces; everything after the
            // LAST ')' is single-space separated, making ppid the second field there.
            $close = strrpos($stat, ')');
            if ($close === false) {
                return false;
            }
            $fields = explode(' ', trim(substr($stat, $close + 1)));
            $pid = isset($fields[1]) ? (int) $fields[1] : 0;
        }

        return false;
    }

    /**
     * Clean a directory recursively
     *
     * @param string $path
     * @return void
     */
    protected function clear_directory($path)
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    }


    /**
     * Remove orphaned ".tmp_<digits>" staging directories from the code tree.
     *
     * file_put_contents_safe() creates these alongside a destination only when
     * rsx-tmp is on a different filesystem, and removes them immediately. This
     * sweep cleans up any left behind by a process that died mid-write. Heavy
     * and symlinked directories are pruned to keep the scan bounded.
     *
     * @param array $roots Root directories to scan
     * @return int Number of directories removed
     */
    protected function remove_orphan_temp_dirs(array $roots)
    {
        $prune = ['vendor', 'node_modules', '.git'];
        $removed = 0;

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $filter = new \RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                function ($current) use ($prune) {
                    // Never follow symlinks (avoids loops and escaping the tree)
                    if ($current->isLink()) {
                        return false;
                    }

                    // Prune heavy directories we never stage into
                    if ($current->isDir() && in_array($current->getFilename(), $prune, true)) {
                        return false;
                    }

                    return true;
                }
            );

            $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

            $to_remove = [];
            foreach ($iterator as $item) {
                if ($item->isDir() && preg_match('/^\.tmp_\d+$/', $item->getFilename())) {
                    $to_remove[] = $item->getPathname();
                }
            }

            // Remove after iteration so we don't mutate the tree we're walking
            foreach ($to_remove as $dir) {
                if (rmdir_recursive($dir)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Count files in a directory recursively
     *
     * @param string $path
     * @return int
     */
    protected function count_files_in_directory($path)
    {
        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Prevent this command from being called via $this->call() from another command
     *
     * This command MUST run in a fresh process to ensure in-memory caches are cleared.
     * Use Rsx_Artisan::passthru() instead of $this->call() when calling from other commands.
     *
     * @return void
     */
    protected function prevent_call_from_another_command()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($trace as $frame) {
            // Check if we're being called from Artisan::call() or Command::call()
            if (isset($frame['class']) && isset($frame['function'])) {
                $class = $frame['class'];
                $function = $frame['function'];

                // Detect $this->call() from another command
                if ($function === 'call' && str_contains($class, 'Command')) {
                    $this->error('');
                    $this->error('[ERROR] FATAL ERROR: rsx:clean cannot be called via $this->call()');
                    $this->error('');
                    $this->error('This command MUST run in a fresh process to properly clear in-memory caches.');
                    $this->error('');
                    $this->error('FIX: Use Rsx_Artisan::passthru() instead of $this->call():');
                    $this->error('');
                    $this->line('  // [ERROR] WRONG - runs in same process, caches remain in memory');
                    $this->line('  $this->call(\'rsx:clean\');');
                    $this->error('');
                    $this->line('  // [OK] CORRECT - fresh process, all caches cleared');
                    $this->line('  Rsx_Artisan::passthru(\'rsx:clean\');');
                    $this->error('');

                    exit(1);
                }
            }
        }
    }
}
