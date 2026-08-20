<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * @REALPATH-EXCEPTION - writes the project-root .env by construction from
 * base_path(), which is the one file whose location is not negotiable.
 */

namespace App\RSpade\Core\Env;

/**
 * Minimal key writer for the project-root .env.
 *
 * Deliberately small. This is NOT a general configuration API - application
 * settings belong in config files under version control, and .env is for
 * deployment-specific values a human edits. It exists for the first-run setup
 * screens, which have to record a decision somebody just made in a browser
 * before the application can serve anything at all.
 *
 * TWO PROPERTIES THAT MATTER:
 *
 * Duplicate keys are COLLAPSED. A .env can end up with a key declared twice - a
 * blank template line plus a real value appended later - and the parser takes
 * the first, so the configured value is silently ignored. That defect cost a
 * container every setting it was given (2026-08-20), and it is not re-created
 * here: an existing key is rewritten in place and any further definitions of it
 * are dropped.
 *
 * The file is rewritten THROUGH its existing path, never replaced by rename, so
 * .env keeps its inode - system/.env is a symlink to it, and swapping the file
 * out from under the link is how that link ends up pointing at nothing.
 */
class Rsx_Env_Writer
{
    /**
     * Write one key. Returns false when the file cannot be read or written.
     */
    public static function set(string $key, string $value): bool
    {
        return self::set_many([$key => $value]);
    }

    /**
     * Write several keys in a single pass.
     *
     * @param array<string,string> $values
     */
    public static function set_many(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        $path = self::path();

        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            return false;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }

        $written = [];
        $out = [];

        foreach ($lines as $line) {
            $matched = null;

            foreach ($values as $key => $value) {
                if (strncmp($line, $key . '=', strlen($key) + 1) === 0) {
                    $matched = $key;
                    break;
                }
            }

            if ($matched === null) {
                $out[] = $line;
                continue;
            }

            // First occurrence becomes the value; later ones are dropped, so the
            // file cannot end up with two definitions of one key.
            if (!isset($written[$matched])) {
                $out[] = $matched . '=' . self::__format($values[$matched]);
                $written[$matched] = true;
            }
        }

        foreach ($values as $key => $value) {
            if (!isset($written[$key])) {
                $out[] = $key . '=' . self::__format($value);
            }
        }

        return @file_put_contents($path, implode("\n", $out) . "\n") !== false;
    }

    /**
     * The project-root .env - one level above the Laravel base path, which is
     * system/. system/.env is a symlink to this file.
     */
    public static function path(): string
    {
        return dirname(base_path()) . '/.env';
    }

    /**
     * Quote only when the value would not survive unquoted: whitespace, or a
     * character the parser treats specially. Plain values stay plain, so the file
     * keeps looking hand-written.
     */
    private static function __format(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9._:\/@+\-]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
