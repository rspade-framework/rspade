<?php

namespace App\RSpade\Core;

/**
 * Polymorphic Field Helper
 *
 * Handles polymorphic field values from form components.
 * Provides parsing, validation, and security checks for polymorphic relationships.
 *
 * Accepts multiple input formats:
 * - JSON string: '{"model":"Contact_Model","id":123}'
 * - Array: ['model' => 'Contact_Model', 'id' => 123]
 * - Object: stdClass with model and id properties
 *
 * Validates the model type against a whitelist of allowed classes.
 *
 * Usage in controllers:
 *
 *     use App\RSpade\Core\Polymorphic_Field_Helper;
 *
 *     // Parse and validate a polymorphic field
 *     $eventable = Polymorphic_Field_Helper::parse($params['eventable'], [
 *         Contact_Model::class,
 *         Project_Model::class,
 *     ]);
 *
 *     // Quick validation with error message
 *     $error = $eventable->validate('Please select an entity');
 *     if ($error) {
 *         $errors['eventable'] = $error;
 *     }
 *
 *     // Or check states manually
 *     if ($eventable->is_empty()) {
 *         $errors['eventable'] = 'Please select a contact or project';
 *     } elseif (!$eventable->is_valid()) {
 *         $errors['eventable'] = 'Invalid entity type selected';
 *     }
 *
 *     // Use the values
 *     $model->eventable_type = $eventable->model;
 *     $model->eventable_id = $eventable->id;
 */
#[Instantiatable]
class Polymorphic_Field_Helper
{
    /**
     * The model class name (e.g., "Contact_Model")
     */
    public ?string $model = null;

    /**
     * The entity ID
     */
    public ?int $id = null;

    /**
     * Whether any value was provided in the input
     */
    private bool $was_provided = false;

    /**
     * Whether the provided model type is in the allowed list
     */
    private bool $model_allowed = false;

    /**
     * List of allowed model basenames for error messages
     */
    private array $allowed_models = [];

    /**
     * Parse a polymorphic field value
     *
     * Accepts multiple formats:
     * - JSON string: '{"model":"Contact_Model","id":123}'
     * - Array: ['model' => 'Contact_Model', 'id' => 123]
     * - Object: stdClass with model and id properties
     *
     * @param string|array|object|null $value The polymorphic value in any supported format
     * @param array $allowed_model_classes Array of allowed model class names (use Model::class syntax)
     * @return self
     */
    public static function parse(string|array|object|null $value, array $allowed_model_classes): self
    {
        $instance = new self();
        $instance->allowed_models = array_map(fn($class) => class_basename($class), $allowed_model_classes);

        if (empty($value)) {
            return $instance;
        }

        // Normalize to array
        $decoded = null;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                return $instance;
            }
        } elseif (is_object($value)) {
            $decoded = (array) $value;
        } elseif (is_array($value)) {
            $decoded = $value;
        }

        if (!$decoded) {
            return $instance;
        }

        $instance->was_provided = true;

        if (!empty($decoded['model']) && !empty($decoded['id'])) {
            $instance->model = $decoded['model'];
            $instance->id = (int) $decoded['id'];
            $instance->model_allowed = in_array($instance->model, $instance->allowed_models);
        }

        return $instance;
    }

    /**
     * Check if no value was provided (empty string or null)
     */
    public function is_empty(): bool
    {
        return !$this->was_provided || $this->model === null || $this->id === null;
    }

    /**
     * Check if the value is valid (provided and model type is allowed)
     */
    public function is_valid(): bool
    {
        return !$this->is_empty() && $this->model_allowed;
    }

    /**
     * Check if a value was provided but is invalid (wrong model type or malformed)
     */
    public function is_invalid(): bool
    {
        return $this->was_provided && !$this->is_valid();
    }

    /**
     * Get the model class name (e.g., "Contact_Model")
     */
    public function get_model(): ?string
    {
        return $this->model;
    }

    /**
     * Get the entity ID
     */
    public function get_id(): ?int
    {
        return $this->id;
    }

    /**
     * Get allowed model names for error messages
     */
    public function get_allowed_models(): array
    {
        return $this->allowed_models;
    }

    /**
     * Validate and return error message if invalid, null if valid
     *
     * Use this for required polymorphic fields.
     *
     * @param string $empty_message Message when field is empty/missing
     * @param string $invalid_message Message when model type is not allowed
     * @return string|null Error message or null if valid
     */
    public function validate(string $empty_message, string $invalid_message = 'Invalid entity type selected'): ?string
    {
        if ($this->is_empty()) {
            return $empty_message;
        }
        if (!$this->model_allowed) {
            return $invalid_message;
        }
        return null;
    }

    /**
     * Validate for optional field (only invalid if provided but wrong type)
     *
     * Use this for optional polymorphic fields where empty is acceptable.
     *
     * @param string $invalid_message Message when model type is not allowed
     * @return string|null Error message or null if valid/empty
     */
    public function validate_optional(string $invalid_message = 'Invalid entity type selected'): ?string
    {
        if ($this->is_empty()) {
            return null; // Empty is OK for optional fields
        }
        if (!$this->model_allowed) {
            return $invalid_message;
        }
        return null;
    }
}
