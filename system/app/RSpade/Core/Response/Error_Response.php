<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Response;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Response\Rsx_Response_Abstract;

/**
 * Unified error response
 *
 * Replaces Form_Error_Response, Auth_Required_Response, Unauthorized_Response, etc.
 */
class Error_Response extends Rsx_Response_Abstract
{
    protected string $error_code;
    protected array $metadata;

    public function __construct(string $error_code, $metadata = null)
    {
        $this->error_code = $error_code;

        // Normalize metadata to array
        if ($metadata === null) {
            $this->metadata = [];
        } elseif (is_string($metadata)) {
            $this->metadata = ['_message' => $metadata];
        } elseif (is_array($metadata)) {
            $this->metadata = $metadata;
        } else {
            $this->metadata = ['_message' => (string)$metadata];
        }

        // Set reason from message or use default
        if (isset($this->metadata['_message'])) {
            $this->reason = $this->metadata['_message'];
        } else {
            $this->reason = Ajax::get_default_message($error_code);
        }

        $this->details = $this->metadata;
        $this->redirect = null;
    }

    public function get_type(): string
    {
        return $this->error_code;
    }

    public function get_error_code(): string
    {
        return $this->error_code;
    }

    public function get_metadata(): array
    {
        return $this->metadata;
    }
}
