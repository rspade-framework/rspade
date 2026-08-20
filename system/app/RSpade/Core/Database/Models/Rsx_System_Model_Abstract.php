<?php

namespace App\RSpade\Core\Database\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Abstract base model for system/internal models
 * 
 * Models extending this class:
 * - Represent server-side only metadata
 * - Are never exported to JavaScript ORM
 * - Include system data like logs, audit trails, IP addresses, etc.
 * 
 * Examples: ip_addresses, activity_logs, system_settings
 */
abstract class Rsx_System_Model_Abstract extends Rsx_Model_Abstract
{
    /**
     * Mark this as a system model that should never be exported
     * 
     * @var bool
     */
    protected $is_system_model = true;
    
    /**
     * System models should never be included in JavaScript ORM exports
     * 
     * @return bool
     */
    public function is_exportable_to_javascript()
    {
        return false;
    }
    
    /**
     * Get metadata indicating this is a system model
     * 
     * @return array
     */
    public function get_system_metadata()
    {
        return [
            'is_system' => true,
            'exportable' => false,
            'model_type' => 'system',
            'description' => 'Internal system model - not exported to client'
        ];
    }
    
    /**
     * Override to ensure all columns are marked as never export for system models
     * 
     * @return array
     */
    public function get_never_exported_columns()
    {
        // For system models, ALL columns should never be exported
        return array_keys($this->getAttributes());
    }
}