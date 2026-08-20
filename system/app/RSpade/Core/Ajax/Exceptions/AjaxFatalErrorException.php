<?php

namespace App\RSpade\Core\Ajax\Exceptions;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Exception thrown when an API method encounters a fatal error
 */
#[Instantiatable]
class AjaxFatalErrorException extends \Exception
{
    public function __construct($message = "Fatal error occurred", $code = 500, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}