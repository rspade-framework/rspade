<?php

namespace App\RSpade\Core\Ajax\Exceptions;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Exception thrown when an API method denies access due to authorization
 */
#[Instantiatable]
class AjaxUnauthorizedException extends \Exception
{
    public function __construct($message = "Unauthorized", $code = 403, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}