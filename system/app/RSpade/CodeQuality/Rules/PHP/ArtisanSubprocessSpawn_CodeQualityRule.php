<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
/**
 * ARTISAN-SPAWN-01 - `php artisan` subprocesses go through Rsx_Artisan, never a raw
 * process function.
 *
 * This is a LOCK-CORRECTNESS rule, not a style rule. A cluster lock belongs to a socket
 * CONNECTION; a subprocess is a different process with a different connection, so a
 * hand-rolled spawn queues behind whatever its own parent holds while the parent sits in
 * waitpid on it. rsx-lockd's deadlock detector cannot see it - the parent's half of the
 * cycle is an OS wait, not a lock wait - so it is a permanent hang with no error. That is
 * not theoretical: it wedged a framework test run for twelve hours on 2026-08-11.
 *
 * Rsx_Artisan attaches --_lock-group so the child inherits the parent's locks instead of
 * deadlocking against them, and it also fixes two things every hand-rolled spawn gets
 * wrong sooner or later: the absolute artisan path (a bare `php artisan` resolves against
 * the CALLER's cwd) and the PHP binary (PHP_BINARY under php-fpm is the fpm executable,
 * which cannot run a script - so a web-triggered spawn silently no-ops).
 *
 * Manual tier on purpose. It lives in Rules/PHP/ (rsx:check) rather than Rules/Manifest/
 * (build-fatal) because a false positive here would brick the build over a string that
 * merely mentions artisan, and the failure this prevents is loud enough to diagnose once
 * you know the shape.
 */
class ArtisanSubprocessSpawn_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Process-spawning functions. `exec` and `proc_open` have their own bans
     * (PHP-EXEC-01, PHP-PROC-01) and are listed here anyway so that an artisan spawn is
     * reported with THIS rule's remediation, which is the one that matters for locks.
     */
    private const SPAWN_FUNCTIONS = [
        'passthru',
        'shell_exec',
        'exec_safe',
        'exec',
        'system',
        'popen',
        'proc_open',
    ];

    public function get_id(): string
    {
        return 'ARTISAN-SPAWN-01';
    }

    public function get_name(): string
    {
        return 'Artisan Subprocess Spawn Check';
    }

    public function get_description(): string
    {
        return 'Requires Rsx_Artisan for `php artisan` subprocesses so cluster locks propagate instead of deadlocking';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (str_contains($file_path, '/vendor/')) {
            return;
        }

        // The rules directory describes the patterns it bans, and Rsx_Artisan IS the
        // sanctioned implementation - it necessarily contains the calls this rule flags.
        if (str_contains($file_path, '/CodeQuality/') || str_contains($file_path, 'Rsx_Artisan.php')) {
            return;
        }

        // The $contents handed to a rule is pre-processed and is NOT line-aligned with the
        // file on disk, so read the file itself. Everything below reports real line numbers.
        $source = file_get_contents($file_path);
        $original_lines = explode("\n", $source);

        // TOKENS, not a line scan. Two reasons, both learned the hard way here:
        //
        //   - Comments and string literals must not produce violations, and the ONLY
        //     reliable way to tell code from text is to ask the parser.
        //   - FileSanitizer::sanitize_php()['lines'] is NOT index-aligned with the file
        //     (it returned 194 entries for a 182-line file), so pairing a sanitized index
        //     with a line number silently reports the WRONG LINE. Token line numbers are
        //     correct by construction.
        foreach (self::__find_spawn_calls($source) as $call) {
            $function = $call['function'];
            $line_num = $call['line'] - 1;
            $original_line = $original_lines[$line_num] ?? '';

            $violation_message = "CRITICAL: `php artisan` spawned with {$function}() - use Rsx_Artisan instead

A cluster lock belongs to a CONNECTION. A subprocess is a different process, so it opens
its own connection and is a stranger to the daemon - including a stranger to its own
parent. If this process holds a lock the child also needs (a site write lock is taken
IMPLICITLY by the first save() to any site-scoped model), the child queues behind its
parent while the parent blocks waiting for the child. Neither can ever move.

rsx-lockd cannot detect that cycle: the parent's half of it is an OS waitpid, not a lock
wait. There is no error and no timeout - the process tree simply stops. It wedged a
framework test run for twelve hours on 2026-08-11.";

            $resolution = "REQUIRED ACTION - route this spawn through App\\RSpade\\Core\\Console\\Rsx_Artisan:

    use App\\RSpade\\Core\\Console\\Rsx_Artisan;

    // streams the child's output through (replaces passthru)
    \$exit = Rsx_Artisan::passthru('rsx:bundle:compile');

    // captures the output (replaces exec_safe / shell_exec)
    \$output = [];
    \$exit = Rsx_Artisan::run('migrate', ['--force'], \$output);

    // fire and forget - returns immediately (replaces a backgrounded `... &`)
    Rsx_Artisan::dispatch_detached('rsx:task:worker');

THE COMMAND NAME AND ITS ARGUMENTS ARE SEPARATE:
Pass argv tokens as an array; Rsx_Artisan escapes each one. Do not build a command string.

    Rsx_Artisan::passthru('rsx:prod:build', ['--force', '--authorized']);

SYNCHRONOUS PROPAGATES, ASYNCHRONOUS DOES NOT:
passthru() and run() always hand this process's lock group to the child, because the
parent is blocked and inheritance is the only correct answer. dispatch_detached() does
NOT, because two processes running concurrently under one lock would destroy the mutual
exclusion both believe they have. Its opt-in is deliberately named
\$propagate_locks_and_i_will_wait - pass true ONLY if this caller genuinely waits for the
spawned process before continuing its own critical section.

ENVIRONMENT vs INVOCATION INTENT:
Rsx_Artisan::run() takes an \$env array for ENVIRONMENT facts (which database, which
mode). Invocation intent rides as an argv flag - standing owner ruling, no exceptions.

    Rsx_Artisan::run('migrate', ['--force'], \$output, ['DB_DATABASE' => \$test_db]);

NOT FOR IN-PROCESS CALLS:
Artisan::call() runs in THIS process on THIS connection and is already reentrant. It needs
none of this and is not flagged.

Full contract: `php artisan rsx:man locks` (LOCK GROUPS) and the Rsx_Artisan class docblock.";

            $this->add_violation(
                $file_path,
                $call['line'],
                $violation_message,
                trim($original_line),
                $resolution,
                'critical'
            );
        }
    }

    /**
     * Every call to a process-spawning function whose ARGUMENTS mention artisan.
     *
     * "Mention artisan" covers both spellings that occur in practice: the command as a
     * string literal (`passthru('php artisan rsx:clean')`) and the artisan path in a
     * variable (`exec_safe('php ' . escapeshellarg($artisan) . ' --version')`). Matching
     * the arguments rather than the whole line is what keeps a neighbouring statement on
     * the same line from implicating an unrelated call.
     *
     * @return array<int, array{function: string, line: int}>
     */
    private static function __find_spawn_calls(string $contents): array
    {
        $tokens = token_get_all($contents);
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $name = strtolower($token[1]);
            if (!in_array($name, self::SPAWN_FUNCTIONS, true)) {
                continue;
            }

            // A method or a declaration is not a call to the global function.
            $previous = self::__significant_token($tokens, $i - 1, -1);
            if (is_array($previous)
                && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }

            $open = self::__significant_index($tokens, $i + 1, 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            if (self::__arguments_mention_artisan($tokens, $open, $count)) {
                $found[] = ['function' => $name, 'line' => $token[2]];
            }
        }

        return $found;
    }

    /** Walk the argument list to its matching close paren, looking for artisan. */
    private static function __arguments_mention_artisan(array $tokens, int $open, int $count): bool
    {
        $depth = 0;

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    return false;
                }
                continue;
            }

            if (is_array($token)
                && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_VARIABLE, T_STRING], true)
                && stripos($token[1], 'artisan') !== false) {
                return true;
            }
        }

        return false;
    }

    /** The nearest non-whitespace, non-comment token from $start in direction $step. */
    private static function __significant_token(array $tokens, int $start, int $step)
    {
        $index = self::__significant_index($tokens, $start, $step);

        return $index === null ? null : $tokens[$index];
    }

    private static function __significant_index(array $tokens, int $start, int $step): ?int
    {
        for ($i = $start; $i >= 0 && $i < count($tokens); $i += $step) {
            $token = $tokens[$i];
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
