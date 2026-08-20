<?php

namespace App\RSpade\Core\Search;

use Exception;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Files\Libreoffice;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Search\Rsx_Text_Extractor_Abstract;

/**
 * Libreoffice_Text_Extractor - extracts text from Office documents (doc/docx/xls/xlsx/ppt/pptx/
 * odt/rtf/...) by shelling out to headless LibreOffice.
 *
 * Sub-routes by mime FAMILY, because the one-size filter does not exist:
 *
 *   Writer   (doc/docx/odt/rtf/...) -> `--convert-to 'txt:Text (encoded):UTF8'`, read the .txt.
 *            The "Text (encoded)" filter is Writer-ONLY.
 *   Calc     (xls/xlsx/ods/...)     -> `--convert-to fods` (flat XML, ONE file, ALL sheets),
 *            then a STREAMING XMLReader pass emits each sheet's name + its cell text. The old
 *            Writer txt filter produces NO output for a Calc document (it silently writes
 *            nothing), which is why every spreadsheet used to FAIL extraction.
 *   Impress  (ppt/pptx/odp/...)     -> `--convert-to fodp` (flat XML), same streaming pass for
 *            each slide's text:p. The Writer txt filter ALSO produces no output for Impress.
 *
 * Streaming (not DOM-loading) the flat XML matters: real workbooks reach 170 sheets, so the
 * reader stops once the assembled text passes the byte cap (plus a small margin) and the service
 * truncates precisely.
 *
 * Shares the soffice-discovery routine (Libreoffice::find_soffice) AND the concurrency semaphore
 * with the thumbnail renderer. The semaphore name 'libreoffice_thumbnail' is HISTORICAL - it
 * literally means "libreoffice slots" cluster-wide, so text extraction and thumbnail rendering
 * draw from ONE shared pool of soffice invocations regardless of what the conversion targets.
 *
 * The master enable/disable switch (rsx.libreoffice.enabled) is checked by the caller
 * (Search_Index_Service) BEFORE invoking this extractor - a disabled LibreOffice yields an
 * UNSUPPORTED row, never a call here.
 *
 * Fail loud: throws on missing binary, no free slot, non-zero exit, or missing output.
 */
class Libreoffice_Text_Extractor extends Rsx_Text_Extractor_Abstract
{
    /**
     * Semaphore name for the max-concurrency quota. HISTORICAL name - it means "libreoffice
     * slots" cluster-wide, shared with Libreoffice_Thumbnail_Renderer (one soffice pool).
     */
    protected const SEMAPHORE_NAME = 'libreoffice_thumbnail';

    /**
     * @param string $source_path
     * @param string $mime
     * @return string
     * @throws Exception
     */
    public static function extract(string $source_path, string $mime): string
    {
        $soffice = Libreoffice::find_soffice();
        if ($soffice === null) {
            throw new Exception('LibreOffice (soffice) is not installed or not configured');
        }

        $timeout = (int) config('rsx.libreoffice.timeout', 30);
        $max_concurrent = (int) config('rsx.libreoffice.max_concurrent', 2);
        $slot_wait = (int) config('rsx.libreoffice.slot_wait_timeout', 30);

        // One shared cluster-wide soffice slot pool (name shared with the thumbnail renderer).
        // A crashed conversion's slot is freed the instant its process dies.
        $slot = RsxLocks::acquire_semaphore(static::SEMAPHORE_NAME, $max_concurrent, $slot_wait);
        if ($slot === null) {
            throw new Exception('LibreOffice concurrency slot unavailable');
        }

        // Private work dir - LibreOffice needs an isolated user profile and an output dir.
        $work_dir = sys_get_temp_dir() . '/rsx_soffice_txt_' . bin2hex(random_bytes(8));
        if (!mkdir($work_dir, 0700, true) && !is_dir($work_dir)) {
            RsxLocks::release_semaphore($slot);
            throw new Exception("Failed to create LibreOffice work dir: {$work_dir}");
        }

        try {
            if (static::__is_spreadsheet_mime($mime)) {
                $fods = static::__convert($soffice, $work_dir, $source_path, 'fods', 'fods', $timeout);
                return static::__stream_flat_xml($fods, true);
            }

            if (static::__is_presentation_mime($mime)) {
                $fodp = static::__convert($soffice, $work_dir, $source_path, 'fodp', 'fodp', $timeout);
                return static::__stream_flat_xml($fodp, false);
            }

            // Writer family (and any other Office mime): the "Text (encoded)" filter emits UTF-8.
            $txt = static::__convert($soffice, $work_dir, $source_path, 'txt:Text (encoded):UTF8', 'txt', $timeout);
            $raw = file_get_contents($txt);
            if ($raw === false) {
                throw new Exception('Failed to read LibreOffice text output for ' . basename($source_path));
            }

            // Defense-in-depth: scrub to valid UTF-8 (same guarantee as Plain_Text_Extractor) so
            // the FULLTEXT store never receives a malformed byte the utf8mb4 column would reject,
            // even if a locale edge case slips past the :UTF8 filter.
            return mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        } finally {
            RsxLocks::release_semaphore($slot);
            static::__rmdir_recursive($work_dir);
        }
    }

    /**
     * True for the Calc (spreadsheet) mime family: modern .xlsx (spreadsheetml), the older .xls
     * (vnd.ms-excel), and OpenDocument .ods (opendocument.spreadsheet, incl. -template).
     *
     * @param string $mime
     * @return bool
     */
    protected static function __is_spreadsheet_mime(string $mime): bool
    {
        return $mime === 'application/vnd.ms-excel'
            || str_contains($mime, 'spreadsheetml')
            || str_contains($mime, 'opendocument.spreadsheet');
    }

    /**
     * True for the Impress (presentation) mime family: modern .pptx (presentationml), the older
     * .ppt (vnd.ms-powerpoint), and OpenDocument .odp (opendocument.presentation, incl. -template).
     *
     * @param string $mime
     * @return bool
     */
    protected static function __is_presentation_mime(string $mime): bool
    {
        return $mime === 'application/vnd.ms-powerpoint'
            || str_contains($mime, 'presentationml')
            || str_contains($mime, 'opendocument.presentation');
    }

    /**
     * Run a single headless soffice conversion and return the absolute path of the produced file.
     *
     * soffice names the output after the source basename with the target extension, and - the
     * important part - it exits 0 EVEN WHEN the write fails (a Calc doc through the Writer txt
     * filter, an encrypted doc, ...). The authoritative success check is therefore the presence
     * of the output file, not the exit code; stderr is surfaced in the failure message.
     *
     * @param string $soffice
     * @param string $work_dir
     * @param string $source_path
     * @param string $convert_to soffice --convert-to argument (e.g. 'fods', 'txt:Text (encoded):UTF8')
     * @param string $output_ext extension soffice will give the produced file (e.g. 'fods', 'txt')
     * @param int $timeout
     * @return string Absolute path to the produced output file.
     * @throws Exception
     */
    protected static function __convert(
        string $soffice,
        string $work_dir,
        string $source_path,
        string $convert_to,
        string $output_ext,
        int $timeout
    ): string {
        $process = new Process([
            $soffice,
            '-env:UserInstallation=file://' . $work_dir . '/profile',
            '--headless',
            '--convert-to',
            $convert_to,
            '--outdir',
            $work_dir,
            $source_path,
        ]);

        // Hard cap the conversion, measured from now (after slot acquisition).
        $process->setTimeout($timeout);
        $process->run();

        $out_path = $work_dir . '/' . pathinfo($source_path, PATHINFO_FILENAME) . '.' . $output_ext;
        if (!file_exists($out_path)) {
            $stderr = trim($process->getErrorOutput());
            throw new Exception(
                'LibreOffice produced no output for ' . basename($source_path)
                . ($stderr !== '' ? ': ' . $stderr : '')
            );
        }

        return $out_path;
    }

    /**
     * Stream a LibreOffice flat-XML file (.fods / .fodp) and assemble its plain text.
     *
     * XMLReader, not DOMDocument: a 170-sheet workbook flat XML is large, so we walk it as a
     * pull stream and stop once the assembled text passes the byte cap plus a margin (the service
     * truncates precisely). Extraction is gated to inside <office:body> so master-slide / style
     * placeholder text (e.g. a slide-number field's literal "<number>") never leaks in.
     *
     * Spreadsheet mode: each <table:table> emits its table:name as a header line, then each
     * populated row emits its cells joined by tabs (a cell's text is the concatenated text of its
     * text:p nodes). Presentation mode: each <text:p> in the body emits one line.
     *
     * @param string $path Absolute path to the flat-XML file.
     * @param bool $is_spreadsheet true = Calc (.fods), false = Impress (.fodp).
     * @return string Extracted UTF-8 text.
     * @throws Exception
     */
    protected static function __stream_flat_xml(string $path, bool $is_spreadsheet): string
    {
        $budget = (int) config('rsx.search.max_text_bytes', 2 * 1024 * 1024);
        // Read a little past the cap so the service can truncate to an exact byte length; the
        // reader itself must only avoid running unbounded over a huge workbook.
        $limit = $budget + 65536;

        $prev_internal = libxml_use_internal_errors(true);

        $reader = \XMLReader::open($path);
        if ($reader === false) {
            libxml_use_internal_errors($prev_internal);
            throw new Exception('Failed to open LibreOffice flat-XML output for ' . basename($path));
        }

        $out = '';
        $row = [];
        $cell = '';
        $in_cell = false;
        $para = '';
        $in_para = false;
        $in_body = false;

        while ($reader->read()) {
            $type = $reader->nodeType;
            $name = $reader->localName;

            if ($type === \XMLReader::ELEMENT) {
                if ($name === 'body') {
                    $in_body = true;
                } elseif (!$in_body) {
                    continue;
                } elseif ($is_spreadsheet) {
                    if ($name === 'table') {
                        $out .= "\n" . (string) $reader->getAttribute('table:name') . "\n";
                    } elseif ($name === 'table-row') {
                        $row = [];
                    } elseif ($name === 'table-cell' || $name === 'covered-table-cell') {
                        $cell = '';
                        $in_cell = true;
                        if ($reader->isEmptyElement) {
                            $row[] = '';
                            $in_cell = false;
                        }
                    }
                } elseif ($name === 'p') {
                    $para = '';
                    $in_para = true;
                    if ($reader->isEmptyElement) {
                        $in_para = false;
                    }
                }
            } elseif ($type === \XMLReader::TEXT || $type === \XMLReader::CDATA) {
                if ($in_cell) {
                    $cell .= $reader->value;
                } elseif ($in_para) {
                    $para .= $reader->value;
                }
            } elseif ($type === \XMLReader::END_ELEMENT) {
                if ($name === 'body') {
                    $in_body = false;
                } elseif ($is_spreadsheet) {
                    if ($name === 'table-cell' || $name === 'covered-table-cell') {
                        if ($in_cell) {
                            $row[] = $cell;
                            $in_cell = false;
                        }
                    } elseif ($name === 'table-row') {
                        $line = trim(implode("\t", $row));
                        if ($line !== '') {
                            $out .= $line . "\n";
                        }
                        $row = [];
                    }
                } elseif ($name === 'p' && $in_para) {
                    $trimmed = trim($para);
                    if ($trimmed !== '') {
                        $out .= $trimmed . "\n";
                    }
                    $in_para = false;
                }
            }

            if (strlen($out) >= $limit) {
                break;
            }
        }

        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors($prev_internal);

        // Defense-in-depth scrub (soffice flat XML is already UTF-8, but the FULLTEXT column must
        // never see a malformed byte).
        return mb_convert_encoding($out, 'UTF-8', 'UTF-8');
    }

    /**
     * Recursively remove a directory tree (best-effort cleanup of the temp work dir).
     *
     * @param string $dir
     * @return void
     */
    protected static function __rmdir_recursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                static::__rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
