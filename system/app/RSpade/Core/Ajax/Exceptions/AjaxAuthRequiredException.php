<?php

namespace App\RSpade\Core\Ajax\Exceptions;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Exception thrown when an API method requires authentication
 */
#[Instantiatable]
class AjaxAuthRequiredException extends \Exception
{
    public function __construct($message = "Authentication Required", $code = 401, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}