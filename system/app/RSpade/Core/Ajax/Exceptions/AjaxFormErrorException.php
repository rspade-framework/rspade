<?php

namespace App\RSpade\Core\Ajax\Exceptions;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Exception thrown when an API method encounters form validation errors
 *
 * This exception carries additional metadata about the errors that can be
 * extracted by the calling code.
 */
#[Instantiatable]
class AjaxFormErrorException extends \Exception
{
    protected array $details;
    
    public function __construct($message = "Form validation error", array $details = [], $code = 422, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->details = $details;
    }
    
    /**
     * Get the error details/metadata
     * 
     * @return array
     */
    public function get_details(): array
    {
        return $this->details;
    }
}