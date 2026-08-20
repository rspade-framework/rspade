<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\JsParsers;

use Symfony\Component\Process\Process;

/**
 * The one place a node RPC helper explains why it did not come up.
 *
 * SIX helpers (js-parser, js-transformer, minify, jqhtml-compile, js-sanitizer,
 * js-code-quality) each hand-rolled the same spawn-then-ping loop and each ended with
 * its own hardcoded guess at the cause. The guesses were wrong, and being wrong in six
 * copies is why they stayed wrong:
 *
 *   "Check that Node.js and Babel dependencies are installed.
 *    Fix: cd system/app/RSpade/Core/JavaScript/resource && npm install"
 *
 * Every clause of that was a defect. The dependencies were present and resolving
 * correctly - the daemon was simply slow to boot on a loaded box. The named directory
 * DOES NOT EXIST (the transformer lives under Core/JsParsers/resource), so an operator
 * following it gets "cd: no such file or directory" and concludes something deeper is
 * broken. And the remedy is actively harmful downstream: node_modules is a
 * framework-OWNED zone, hard-synced with rsync --delete, so `npm install` there writes
 * unauthorized content into an owned zone and makes the tamper gate refuse every future
 * update - a dead-end that cost a downstream app a full night (2026-08-11).
 *
 * So: REPORT WHAT WAS OBSERVED, never a guess. Three conditions that used to collapse
 * into one message are now distinguished by evidence - the process died (with its own
 * stderr quoted), the socket never appeared, or the socket appeared but nothing answered.
 * The remediation follows from the observation, and for a genuinely absent module it is
 * `rsx:framework:pull` (an absence in an owned zone is a sync defect, repairable only by
 * the updater) - except in the monorepo, where the framework developer does own
 * system/node_modules and npm install is the correct answer.
 */
class Rpc_Startup_Diagnostics
{
    /**
     * Build the failure message for a helper that did not answer within its budget.
     *
     * @param string        $label         Human name, e.g. "JS Transformer"
     * @param string        $socket_path   Absolute unix socket path we polled
     * @param string        $server_script Absolute path to the node entry script
     * @param int           $waited_ms     How long we actually waited
     * @param Process|null  $process       The spawned process, if we still hold it
     */
    public static function failure_message(
        string $label,
        string $socket_path,
        string $server_script,
        int $waited_ms,
        ?Process $process = null
    ): string {
        $lines = [];
        $lines[] = "{$label} RPC server did not answer within {$waited_ms}ms.";
        $lines[] = '';
        $lines[] = 'Observed:';

        // 1. Did the thing we spawned survive?
        $died = false;
        $stderr = '';
        if ($process !== null) {
            if ($process->isRunning()) {
                $lines[] = '  - the node process is RUNNING but has not answered a ping yet';
            } else {
                $died = true;
                $exit = $process->getExitCode();
                $lines[] = '  - the node process EXITED (code ' . ($exit === null ? 'unknown' : $exit) . ')';
                $stderr = trim((string) $process->getErrorOutput());
            }
        }

        // 2. What is on disk where the socket should be?
        if (!file_exists($socket_path)) {
            $lines[] = '  - no socket at ' . $socket_path;
            $dir = dirname($socket_path);
            if (!is_dir($dir)) {
                $lines[] = '  - its directory does not exist either: ' . $dir;
            } elseif (!is_writable($dir)) {
                $lines[] = '  - its directory is NOT WRITABLE: ' . $dir;
            }
        } else {
            $lines[] = '  - a socket file EXISTS at ' . $socket_path;
            $lines[] = '  - ...but nothing served a ping on it, so it is stale or the'
                . ' daemon behind it is wedged';
        }

        // 3. Is the entry script even there?
        if (!file_exists($server_script)) {
            $lines[] = '  - the server script is MISSING: ' . $server_script;
        }

        // 4. Anything the daemon itself said.
        if ($stderr !== '') {
            $lines[] = '';
            $lines[] = 'The daemon reported:';
            foreach (array_slice(explode("\n", $stderr), 0, 12) as $line) {
                $lines[] = '  ' . rtrim($line);
            }
        }

        $lines[] = '';
        $lines[] = 'Likely cause and fix:';
        foreach (self::__remedies($died, $stderr, $socket_path, $server_script) as $remedy) {
            $lines[] = '  ' . $remedy;
        }

        return implode("\n", $lines);
    }

    /**
     * Remedies, ordered by what the evidence actually supports.
     *
     * @return string[]
     */
    private static function __remedies(bool $died, string $stderr, string $socket_path, string $server_script): array
    {
        // A real module resolution failure names itself; nothing else may claim it.
        $module_missing = $died
            && (str_contains($stderr, 'MODULE_NOT_FOUND') || str_contains($stderr, "Cannot find module"));

        if ($module_missing) {
            if (config('rsx.code_quality.is_framework_developer', false)) {
                return [
                    'A node module genuinely failed to resolve. In the framework monorepo you own',
                    'system/node_modules, so install it there:  cd ' . base_path() . ' && npm install',
                ];
            }

            return [
                'A node module genuinely failed to resolve. system/node_modules is a',
                'framework-OWNED zone that ships with the release and is hard-synced on every',
                'update, so an absence there is a SYNC DEFECT, not something to install over:',
                '    php artisan rsx:framework:pull',
                'Do NOT run npm install inside system/ - writing into an owned zone makes the',
                'tamper gate refuse every future framework update.',
            ];
        }

        if (!file_exists($server_script)) {
            return [
                'The server script is not on disk, which means the framework tree is incomplete:',
                '    php artisan rsx:framework:pull',
            ];
        }

        if (file_exists($socket_path)) {
            return [
                'A stale or wedged daemon owns the socket. Find it and stop it, then retry:',
                '    pgrep -af -- "--socket=' . $socket_path . '"',
            ];
        }

        // The process exited AND said why. That is a hard error, not a slow boot - retrying it
        // or raising the wait budget changes nothing.
        if ($died && trim($stderr) !== '') {
            return [
                'The daemon EXITED with an error and the stderr printed above is the cause -',
                'this is not a slow boot, so retrying or raising the ready-wait budget will not',
                'help. Read that stderr and fix what it names.',
            ];
        }

        // The default, and by far the commonest: it was just slow.
        return [
            'Most often the daemon was simply slow to boot - a loaded box, a backup run, or',
            'a cold node start. The next attempt spawns it again, so re-running usually',
            'succeeds. Raise the budget if this box is consistently slow:',
            '    rsx.javascript.rpc_server_ready_wait_ms  (config/rsx.php)',
            'Check for daemons stranded on a path that no longer exists:',
            '    pgrep -af "node .*server\\.js"',
        ];
    }
}
