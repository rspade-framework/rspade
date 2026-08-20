<?php

namespace App\RSpade\Core\Search;

use Exception;
use App\RSpade\Core\Search\Rsx_Text_Extractor_Abstract;

/**
 * Plain_Text_Extractor - trivial passthrough for text files (txt/md/csv/json/...).
 *
 * Reads the file and scrubs it to valid UTF-8. A file larger than
 * config('rsx.search.max_text_bytes') is NOT failed: it reads AT MOST that many bytes (an fread
 * cap, so a 25 MB .eml is never slurped whole) and returns the prefix. The service records the
 * truncation on the index row (metadata truncated=true + original_bytes) - recorded truncation
 * is not silent truncation, so a partial index is discoverable rather than a wrong-answer secret.
 */
class Plain_Text_Extractor extends Rsx_Text_Extractor_Abstract
{
    /**
     * @param string $source_path
     * @param string $mime
     * @return string
     * @throws Exception
     */
    public static function extract(string $source_path, string $mime): string
    {
        $max_bytes = (int) config('rsx.search.max_text_bytes', 2 * 1024 * 1024);

        $size = filesize($source_path);
        if ($size === false) {
            throw new Exception('Failed to stat text file ' . basename($source_path));
        }

        if ($size === 0) {
            return '';
        }

        // Read at most the cap - never slurp a huge file into memory. The service compares
        // filesize() against the cap to detect (and record) that this was a truncated read.
        $to_read = ($size > $max_bytes) ? $max_bytes : $size;

        $handle = fopen($source_path, 'rb');
        if ($handle === false) {
            throw new Exception('Failed to open text file ' . basename($source_path));
        }
        $raw = fread($handle, $to_read);
        fclose($handle);
        if ($raw === false) {
            throw new Exception('Failed to read text file ' . basename($source_path));
        }

        // Scrub to valid UTF-8, DROPPING (not substituting) any invalid or partial trailing
        // sequence so a byte-capped read never emits a split multibyte character. A final
        // character-boundary cut is defense-in-depth against an off-by-one at the cap.
        $prev_substitute = mb_substitute_character();
        mb_substitute_character('none');
        $text = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        mb_substitute_character($prev_substitute);

        return mb_strcut($text, 0, $max_bytes, 'UTF-8');
    }
}
