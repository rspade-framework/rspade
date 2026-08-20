<?php

namespace App\RSpade\Core\Search;

/**
 * Rsx_Extraction_Unsupported_Exception - an extractor declaring that a blob CANNOT be extracted
 * by design, not that extraction FAILED.
 *
 * Thrown by a concrete Rsx_Text_Extractor_Abstract when the source is structurally impossible to
 * read - the canonical case is a password-protected / encrypted document. Search_Index_Service
 * catches this BEFORE the generic \Throwable catch and records the blob as UNSUPPORTED (with this
 * exception's message as the reason), NOT FAILED. FAILED implies a retry might succeed once a
 * binary/config is fixed; an encrypted document will never extract, so it belongs in the
 * UNSUPPORTED bucket and must not pollute the failed-audit signal.
 *
 * The message is the human reason (e.g. 'password-protected PDF') and is stored on the index
 * row's error column - yes, an UNSUPPORTED row may carry a reason there.
 */
class Rsx_Extraction_Unsupported_Exception extends \Exception
{
}
