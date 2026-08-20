<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Response;

/**
 * Base class for special RSX response types
 */
#[Instantiatable]
abstract class Rsx_Response_Abstract
{
    protected string $reason;
    protected ?string $redirect;
    protected array $details;
    
    /**
     * Get the response type identifier
     */
    abstract public function get_type(): string;
    
    /**
     * Get the reason message
     */
    public function get_reason(): string
    {
        return $this->reason;
    }
    
    /**
     * Get the redirect URL (if any)
     */
    public function get_redirect(): ?string
    {
        return $this->redirect;
    }
    
    /**
     * Get additional details
     */
    public function get_details(): array
    {
        return $this->details;
    }
}