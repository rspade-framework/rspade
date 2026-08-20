<?php

namespace App\RSpade\Core\Search;

use Exception;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Search\Rsx_Extraction_Unsupported_Exception;
use App\RSpade\Core\Search\Rsx_Text_Extractor_Abstract;

/**
 * Pdftotext_Text_Extractor - extracts the text layer of a PDF via poppler's `pdftotext`.
 *
 * Runs `pdftotext -enc UTF-8 <path> -` (output to stdout). A PDF whose pages are all images has
 * no text layer and yields empty output - that is a VALID EXTRACTED result (OCR is out of v1
 * scope), NOT a failure. Only a missing binary or a non-zero pdftotext exit is a failure (throw).
 *
 * A password-protected (encrypted) PDF is UNSUPPORTED, not FAILED: pdftotext exits non-zero with
 * "Command Line Error: Incorrect password" on stderr. That is structural (a retry never helps),
 * so this extractor throws Rsx_Extraction_Unsupported_Exception and the service records it
 * UNSUPPORTED with the reason instead of polluting the failed-audit signal.
 */
class Pdftotext_Text_Extractor extends Rsx_Text_Extractor_Abstract
{
    /**
     * @param string $source_path
     * @param string $mime
     * @return string
     * @throws Rsx_Extraction_Unsupported_Exception
     * @throws Exception
     */
    public static function extract(string $source_path, string $mime): string
    {
        $binary = static::__find_pdftotext();
        if ($binary === null) {
            throw new Exception('pdftotext (poppler-utils) is not installed or not configured');
        }

        $timeout = (int) config('rsx.search.timeout', 30);

        $process = new Process([$binary, '-enc', 'UTF-8', $source_path, '-']);
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            // Encrypted / password-protected PDF: structurally unextractable, not a failure.
            if (stripos($stderr, 'incorrect password') !== false) {
                throw new Rsx_Extraction_Unsupported_Exception('password-protected PDF');
            }

            throw new Exception('pdftotext failed for ' . basename($source_path) . ': ' . $stderr);
        }

        // Empty output = a PDF with no text layer (all-image). Valid EXTRACTED result.
        return $process->getOutput();
    }

    /**
     * Locate the pdftotext binary: explicit config path first, then PATH, then common install
     * locations. Mirrors Libreoffice::find_soffice(). Returns null if unavailable.
     *
     * @return string|null
     */
    protected static function __find_pdftotext(): ?string
    {
        $configured = config('rsx.search.pdftotext_binary_path');
        if (!empty($configured)) {
            return is_executable($configured) ? $configured : null;
        }

        $found = trim((string) shell_exec('bash -c ' . escapeshellarg('command -v pdftotext 2>/dev/null')));
        if ($found !== '' && is_executable($found)) {
            return $found;
        }

        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
