<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Console;

/**
 * FRAMEWORK-INTERNAL COMMAND FLAGS (the `--_` convention).
 *
 * A flag whose ONLY callers are the framework itself (the updater, one command shelling to
 * another) is spelled `--_<name-with-hyphens>` and is NEVER registered as an InputOption.
 * system/artisan lifts every `--_`-prefixed argv token into $GLOBALS['__rsx_internal_flags']
 * and STRIPS it from argv before Symfony parses, so:
 *
 *   - it appears in no `php artisan list` / `php artisan help <cmd>` output;
 *   - passing one can never produce an "unknown option" error;
 *   - a command reads it here instead of via $this->option().
 *
 * Rationale: Symfony/Laravel can hide a whole COMMAND ($hidden = true) but has no per-OPTION
 * hiding, and invocation intent must ride as --flags, never env prefixes (standing owner
 * ruling). This class is the booted-world reader for the pre-boot strip; the global array is
 * plain because the strip runs before any autoloader exists.
 *
 * Convention: `--_` prefix, hyphens after it (`--_no-system-reset`), matching every other
 * artisan flag's separator.
 */
class Rsx_Internal_Flags
{
    /** The argv global the pre-boot strip in system/artisan populates. */
    private const GLOBAL_KEY = '__rsx_internal_flags';

    /**
     * Was $flag passed on this process's command line (before the pre-boot strip removed it)?
     *
     * @param string $flag Full token INCLUDING the leading `--_` (e.g. '--_no-system-reset').
     */
    public static function has(string $flag): bool
    {
        return in_array($flag, self::all(), true);
    }

    /**
     * The VALUE of a `--_name=value` internal flag, or null when it was not passed.
     *
     * The pre-boot strip lifts whole argv tokens, so a valued flag arrives here intact as
     * `--_lock-group=g-4242-a91c3f` and this is what splits it. A valueless spelling
     * (`--_name`) reads as null - use has() to ask whether it is present at all.
     *
     * @param string $flag Flag name INCLUDING the leading `--_`, WITHOUT the `=` (e.g. '--_lock-group').
     */
    public static function get(string $flag): ?string
    {
        $prefix = $flag . '=';

        foreach (self::all() as $token) {
            if (str_starts_with($token, $prefix)) {
                $value = substr($token, strlen($prefix));

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    /**
     * Every internal flag lifted from argv this process, in the order encountered.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $flags = $GLOBALS[self::GLOBAL_KEY] ?? [];

        return is_array($flags) ? array_values($flags) : [];
    }

    /**
     * Declare an internal flag for THIS process.
     *
     * Narrow seam for the two cases argv cannot cover: an in-process delegation that must run
     * as if the flag had been passed (Artisan::call() cannot pass a token the pre-boot strip
     * would have removed), and tests. Idempotent.
     */
    public static function set(string $flag): void
    {
        if (!isset($GLOBALS[self::GLOBAL_KEY]) || !is_array($GLOBALS[self::GLOBAL_KEY])) {
            $GLOBALS[self::GLOBAL_KEY] = [];
        }

        if (!in_array($flag, $GLOBALS[self::GLOBAL_KEY], true)) {
            $GLOBALS[self::GLOBAL_KEY][] = $flag;
        }
    }

    /** Drop a previously-set internal flag (test seam; no production caller). */
    public static function clear(string $flag): void
    {
        if (!isset($GLOBALS[self::GLOBAL_KEY]) || !is_array($GLOBALS[self::GLOBAL_KEY])) {
            return;
        }

        $GLOBALS[self::GLOBAL_KEY] = array_values(array_filter(
            $GLOBALS[self::GLOBAL_KEY],
            static fn ($f) => $f !== $flag
        ));
    }
}
