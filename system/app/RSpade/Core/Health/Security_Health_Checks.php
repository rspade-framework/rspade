<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use Illuminate\Support\Facades\Http;

/**
 * Security_Health_Checks - active web-exposure probes for rsx:health.
 *
 * A correctly configured deployment serves ONLY system/public; sensitive files
 * (.env, the .git repo, the IDE bridge token dir) live outside that docroot and must
 * never be reachable over HTTP. Rather than trust that invariant, this check PROVES it:
 * it fetches each sensitive path from our OWN APP_URL and content-matches the response
 * against the real on-disk file. A match = the file is being served = misconfigured
 * docroot / autoindex = hard FAIL. This is the real defense against a bad web-server
 * setup (it does not rely on file permissions, which a stray `chmod` can wipe).
 *
 * Content-match (not status code) is deliberate: an SPA/catch-all that answers 200 with
 * index.html for unknown paths would false-positive a status-only check.
 */
class Security_Health_Checks
{
    /** HTTP timeout (seconds) for each probe. */
    private const PROBE_TIMEOUT = 6;

    /**
     * Probe our own APP_URL for served sensitive files. One row per target; a served
     * file is a FAIL, an unreachable own-domain degrades to WARN (never a false pass).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Health_Check('Web Exposure')]
    public static function web_exposure(): array
    {
        $base = rtrim((string) config('app.url'), '/');
        if ($base === '') {
            return [[
                'label' => 'Web Exposure',
                'status' => 'FAIL',
                'detail' => 'APP_URL is not set - cannot probe for exposed sensitive files',
                'remediation' => 'set APP_URL in .env to this host',
            ]];
        }

        // Preflight: is our own domain reachable at all? If not, do not report false OKs.
        if (!self::__reachable($base)) {
            return [[
                'label' => 'Web Exposure',
                'status' => 'WARN',
                'detail' => "could not reach {$base} to run exposure probes",
                'remediation' => 'confirm APP_URL is correct and the site is up, then re-run',
            ]];
        }

        $project_root = dirname(base_path());
        $rows = [];

        // .env (the real file is the project-root .env; system/.env symlinks to it).
        $rows[] = self::__probe_file(
            '.env exposure',
            $base . '/.env',
            $project_root . '/.env',
            self::__env_signature($project_root . '/.env')
        );

        // .git repo (HEAD is the canonical dumpability canary; config leaks remote URLs).
        $rows[] = self::__probe_file(
            '.git/HEAD exposure',
            $base . '/.git/HEAD',
            $project_root . '/.git/HEAD',
            null // signature derived from the file's own (small, distinctive) content
        );
        $rows[] = self::__probe_file(
            '.git/config exposure',
            $base . '/.git/config',
            $project_root . '/.git/config',
            'repositoryformatversion'
        );

        // IDE bridge token directory (the grant token must never be web-served).
        $rows[] = self::__probe_bridge($base);

        return $rows;
    }

    /**
     * True if the base URL answers at all (any HTTP status, including a redirect, counts
     * as reachable; only a transport/connection failure counts as unreachable).
     *
     * @param string $base
     * @return bool
     */
    private static function __reachable(string $base): bool
    {
        try {
            Http::withOptions(['verify' => false, 'allow_redirects' => false])
                ->timeout(self::PROBE_TIMEOUT)
                ->get($base . '/');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Probe a single on-disk sensitive file: FAIL if fetching its URL returns its actual
     * content, OK if not served (or not present on disk), WARN on transport error.
     *
     * @param string $label
     * @param string $url
     * @param string $real_path Absolute path of the real file on disk.
     * @param string|null $signature A distinct substring that must appear in the served
     *        response to count as exposure. Null = use the file's own trimmed content.
     * @return array<string, mixed>
     */
    private static function __probe_file(string $label, string $url, string $real_path, ?string $signature): array
    {
        if (!is_file($real_path)) {
            return [
                'label' => $label,
                'status' => 'OK',
                'detail' => 'not present on disk - nothing to expose',
                'remediation' => null,
            ];
        }

        if ($signature === null) {
            $signature = trim((string) file_get_contents($real_path));
        }
        if ($signature === '') {
            return [
                'label' => $label,
                'status' => 'OK',
                'detail' => 'file is empty - no signature to match',
                'remediation' => null,
            ];
        }

        try {
            $response = Http::withOptions(['verify' => false, 'allow_redirects' => false])
                ->timeout(self::PROBE_TIMEOUT)
                ->get($url);
        } catch (\Throwable $e) {
            return [
                'label' => $label,
                'status' => 'WARN',
                'detail' => "probe of {$url} failed: " . $e->getMessage(),
                'remediation' => 'retry; if it persists, verify manually that the path is not served',
            ];
        }

        if ($response->status() === 200 && str_contains($response->body(), $signature)) {
            return [
                'label' => $label,
                'status' => 'FAIL',
                'detail' => "SERVED over HTTP at {$url} - the real file's content is web-readable",
                'remediation' => 'point the web-server docroot at system/public ONLY; deny dotfiles/.git/.env',
            ];
        }

        return [
            'label' => $label,
            'status' => 'OK',
            'detail' => 'not web-served',
            'remediation' => null,
        ];
    }

    /**
     * Probe the IDE bridge token directory by writing a throwaway random marker into it
     * and asking the web server for it via the candidate misconfig URLs. Cleans up the
     * marker regardless of outcome.
     *
     * @param string $base
     * @return array<string, mixed>
     */
    private static function __probe_bridge(string $base): array
    {
        $bridge_rel = trim((string) config('rsx.ide_integration.bridge_path', 'storage/rsx-ide-bridge'), '/');
        // Resolved the same way Ide_Bridge_Token::bridge_dir() does: the configured path is
        // relative to the directory CONTAINING storage/, and volatile storage may have been
        // relocated out of system/ to the project root.
        $bridge_dir = dirname(storage_path()) . '/' . $bridge_rel;
        if (!is_dir($bridge_dir)) {
            return [
                'label' => 'IDE bridge dir exposure',
                'status' => 'OK',
                'detail' => 'bridge directory not present - nothing to expose',
                'remediation' => null,
            ];
        }

        $marker_name = 'probe-' . bin2hex(random_bytes(16)) . '.txt';
        $marker_body = bin2hex(random_bytes(24));
        $marker_path = $bridge_dir . '/' . $marker_name;

        try {
            file_put_contents_safe($marker_path, $marker_body);

            // Whatever the layout (project-root storage/ or the historic system/storage),
            // a misconfigured docroot at system/ serves /storage/..., one at the project
            // root serves /system/storage/... - probe both spellings either way.
            $candidates = [
                $base . '/' . $bridge_rel . '/' . $marker_name,
                $base . '/system/' . $bridge_rel . '/' . $marker_name,
            ];

            foreach ($candidates as $url) {
                try {
                    $response = Http::withOptions(['verify' => false, 'allow_redirects' => false])
                        ->timeout(self::PROBE_TIMEOUT)
                        ->get($url);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($response->status() === 200 && str_contains($response->body(), $marker_body)) {
                    return [
                        'label' => 'IDE bridge dir exposure',
                        'status' => 'FAIL',
                        'detail' => "SERVED over HTTP at {$url} - the grant token directory is web-readable",
                        'remediation' => 'point the web-server docroot at system/public ONLY',
                    ];
                }
            }
        } finally {
            if (is_file($marker_path)) {
                @unlink($marker_path);
            }
        }

        return [
            'label' => 'IDE bridge dir exposure',
            'status' => 'OK',
            'detail' => 'not web-served',
            'remediation' => null,
        ];
    }

    /**
     * A distinctive signature line from a .env file (the APP_KEY line if present, else
     * the first KEY=VALUE line). Empty string if none - the caller then treats the file
     * as having no matchable secret.
     *
     * @param string $env_path
     * @return string
     */
    private static function __env_signature(string $env_path): string
    {
        if (!is_file($env_path)) {
            return '';
        }
        $content = (string) file_get_contents($env_path);
        if (preg_match('/^APP_KEY=.{8,}$/m', $content, $m)) {
            return trim($m[0]);
        }
        if (preg_match('/^[A-Z0-9_]+=.+$/m', $content, $m)) {
            return trim($m[0]);
        }
        return '';
    }
}
