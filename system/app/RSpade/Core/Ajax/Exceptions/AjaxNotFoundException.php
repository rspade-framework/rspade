<?php

namespace App\RSpade\Core\Ajax\Exceptions;

use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Exception thrown when an API method reports ERROR_NOT_FOUND through Ajax::internal().
 *
 * WHY IT EXISTS. Ajax::internal() converts a coded error response into an exception, and
 * whoever catches it has to convert it BACK into a code for the client. Not-found and
 * validation both used to raise AjaxFormErrorException, so the batch transport - which
 * catches, classifies and re-encodes - had no way to tell them apart and stamped every
 * not-found as 'validation'. A client that branches on Ajax.ERROR_NOT_FOUND (the ORM
 * fetch path does) therefore behaved differently in batched (debug/production) mode than
 * in development. This subclass carries the distinction.
 *
 * It EXTENDS AjaxFormErrorException deliberately: every existing catch of that type keeps
 * catching not-found exactly as it did (the CLI ajax runner, app code around
 * Ajax::internal()), so the direct path's behavior is unchanged. Only a caller that wants
 * the distinction - the batch controller - catches this narrower type first.
 */
#[Instantiatable]
class AjaxNotFoundException extends AjaxFormErrorException
{
    public function __construct($message = "Record not found", array $details = [], $code = 404, \Throwable $previous = null)
    {
        parent::__construct($message, $details, $code, $previous);
    }
}
