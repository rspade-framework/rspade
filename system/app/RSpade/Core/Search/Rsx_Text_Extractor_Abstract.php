<?php

namespace App\RSpade\Core\Search;

/**
 * Rsx_Text_Extractor_Abstract - base for a "bytes on disk -> plain text" extractor.
 *
 * One concrete extractor per format family (PDF, Office, plain text). Search_Index_Service
 * resolves an extractor by mime via config('rsx.search.extractors') and calls extract() with
 * the resident blob's absolute path AND its detected mime (the extractor may sub-route by mime
 * family - e.g. LibreOffice handles Writer/Calc/Impress documents differently).
 *
 * FAIL LOUD: extract() throws on any failure - the service catches the throw and records the
 * blob as FAILED (with the exception message). An extractor NEVER returns partial/degraded
 * output or an empty string to mask a failure. An empty string is a VALID result only when the
 * document genuinely contains no text (e.g. a PDF that is all images) - that is EXTRACTED, not
 * FAILED (OCR is out of scope).
 *
 * A blob that is structurally impossible to extract by design (encrypted / password-protected)
 * is NOT a failure: throw Rsx_Extraction_Unsupported_Exception with a human reason and the
 * service records it UNSUPPORTED (with the reason), keeping the failed-audit signal clean.
 */
#[Monoprogenic]
abstract class Rsx_Text_Extractor_Abstract
{
    /**
     * Extract plain text from the file at $source_path.
     *
     * @param string $source_path Absolute path to the resident blob.
     * @param string $mime The detected mime type of the blob (for format-family sub-routing).
     * @return string Extracted UTF-8 text (may be empty for a text-free document).
     * @throws Rsx_Extraction_Unsupported_Exception when the source cannot be extracted by design.
     * @throws \Throwable on any other extraction failure.
     */
    abstract public static function extract(string $source_path, string $mime): string;
}
