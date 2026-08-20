<?php

namespace App\RSpade\Core\Files;

/**
 * Libreoffice - shared helpers for the headless LibreOffice (soffice) integration.
 *
 * Home of the single soffice-discovery routine used by BOTH the thumbnail renderer
 * (Libreoffice_Thumbnail_Renderer) and the text extractor (Libreoffice_Text_Extractor), so
 * binary discovery has exactly one implementation. The counting semaphore that bounds
 * concurrent soffice invocations cluster-wide is shared by name ('libreoffice_thumbnail')
 * across those callers - one soffice slot pool regardless of what the conversion targets.
 */
class Libreoffice
{
    /**
     * Locate the soffice binary: explicit config path first, then PATH, then common install
     * locations. Returns null if unavailable.
     *
     * @return string|null
     */
    public static function find_soffice(): ?string
    {
        $configured = config('rsx.libreoffice.binary_path');
        if (!empty($configured)) {
            return is_executable($configured) ? $configured : null;
        }

        $found = trim((string) shell_exec('bash -c ' . escapeshellarg('command -v soffice 2>/dev/null')));
        if ($found !== '' && is_executable($found)) {
            return $found;
        }

        foreach (['/usr/bin/soffice', '/usr/local/bin/soffice', '/opt/libreoffice/program/soffice'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * rsx:health probe: is headless LibreOffice available (or intentionally disabled)?
     *
     * A public static `#[Health_Check('label')]` (bare marker attribute - never a defined
     * class) returning a row per Health_Check_Runner's contract. When the feature is
     * switched off by config it is an INFO, not a FAIL - a box that never renders Office
     * docs does not need soffice.
     *
     * @return array
     */
    #[Health_Check('LibreOffice (soffice)')]
    public static function soffice_available(): array
    {
        if (config('rsx.libreoffice.enabled', true) === false) {
            return ['status' => 'INFO', 'detail' => 'disabled by config (rsx.libreoffice.enabled=false)'];
        }

        $binary = static::find_soffice();
        if ($binary === null) {
            return [
                'status' => 'FAIL',
                'detail' => 'soffice not found (Office thumbnails, text extraction, and PDF renditions need it)',
                'remediation' => 'apt-get install -y --no-install-recommends libreoffice',
            ];
        }

        return ['status' => 'OK', 'detail' => 'soffice at ' . $binary];
    }
}
