<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Search;

/**
 * Search_Health_Checks - external-binary probe for the document text-extraction pipeline.
 *
 * The poppler-utils binaries (pdftotext, pdfinfo) back the PDF text extractor. Declared
 * in the Search domain alongside the extractor that needs them. The index-backlog health
 * check lives on Search_Index_Service (it owns the queue counts); this holder is only the
 * binary presence probe.
 *
 * Each method is a public static `#[Health_Check('label')]` (a bare marker attribute -
 * never a defined class) returning a row per Health_Check_Runner's contract.
 */
class Search_Health_Checks
{
    /**
     * poppler-utils (pdftotext + pdfinfo). Both ship from the same package, so a single
     * FAIL covers either being missing.
     *
     * @return array
     */
    #[Health_Check('poppler-utils (pdftotext)')]
    public static function poppler_utils(): array
    {
        $pdftotext = static::__locate('pdftotext', config('rsx.search.pdftotext_binary_path'));
        $pdfinfo = static::__locate('pdfinfo', null);

        $missing = [];
        if ($pdftotext === null) {
            $missing[] = 'pdftotext';
        }
        if ($pdfinfo === null) {
            $missing[] = 'pdfinfo';
        }

        if (!empty($missing)) {
            return [
                'status' => 'FAIL',
                'detail' => 'missing poppler binary: ' . implode(', ', $missing),
                'remediation' => 'apt-get install -y poppler-utils',
            ];
        }

        return ['status' => 'OK', 'detail' => 'pdftotext at ' . $pdftotext];
    }

    /**
     * Locate a binary: explicit config path first, then PATH, then common install
     * locations. Mirrors Libreoffice::find_soffice() / Pdftotext_Text_Extractor discovery.
     *
     * @param string $name
     * @param string|null $configured
     * @return string|null
     */
    protected static function __locate(string $name, ?string $configured): ?string
    {
        if (!empty($configured)) {
            return is_executable($configured) ? $configured : null;
        }

        $found = trim((string) shell_exec('bash -c ' . escapeshellarg('command -v ' . escapeshellarg($name) . ' 2>/dev/null')));
        if ($found !== '' && is_executable($found)) {
            return $found;
        }

        foreach (['/usr/bin/' . $name, '/usr/local/bin/' . $name] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
