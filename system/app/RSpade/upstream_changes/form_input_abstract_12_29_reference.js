/**
 * Form_Input_Abstract - Base class for all form input widgets
 *
 * Reference implementation for form_input_abstract_12_29.txt migration guide.
 * Copy this to: rsx/theme/components/inputs/form_input_abstract.js
 *
 * This class implements the template method pattern, handling:
 * - Pre-initialization value buffering
 * - val() getter/setter logic
 * - Event triggers (trigger('val') on all changes)
 *
 * CONCRETE CLASS CONTRACT:
 *
 * REQUIRED - Implement these methods:
 * - _get_value()        Return the current DOM/component value
 * - _set_value(value)   Set the DOM/component value
 *
 * OPTIONAL - Override if needed:
 * - _transform_value(value)   Transform buffered value for getter return
 *
 * REQUIRED - In on_ready():
 * - Call this._mark_ready() when component is fully initialized
 * - Setup user interaction event handlers that trigger both 'input' and 'val'
 *
 * Example Minimal Implementation:
 *
 *     class My_Input extends Form_Input_Abstract {
 *         _get_value() {
 *             return this.$sid('input').val();
 *         }
 *
 *         _set_value(value) {
 *             this.$sid('input').val(value || '');
 *         }
 *
 *         on_ready() {
 *             this._mark_ready();
 *
 *             const that = this;
 *             this.$sid('input').on('input', function() {
 *                 const value = that.val();
 *                 that.trigger('input', value);
 *                 that.trigger('val', value);
 *             });
 *         }
 *     }
 *
 * EVENT CONTRACT:
 * - 'val' event fires on ALL value changes (handled by base val() setter)
 * - 'input' event fires on user interaction ONLY (handled by concrete class)
 * - Concrete classes trigger BOTH 'input' and 'val' on user interaction
 *
 * All widgets automatically have .Form_Input_Abstract class via jqhtml.
 * Form_Field sets data-name attribute for form integration.
 *
 * See: rsx/theme/components/inputs/CLAUDE.md for implementation details
 */
class Form_Input_Abstract extends Component {
    /**
     * Initialize buffering state.
     * Concrete classes should call super.on_create() if they override this.
     */
    on_create() {
        this._pending_value = null;
        this._is_ready = false;
    }

    /**
     * val() - Get or set the current value
     *
     * This method handles all buffering and event logic. Concrete classes
     * should NOT override this method. Instead, implement _get_value(),
     * _set_value(), and optionally _transform_value().
     *
     * @param {*} [value] - If provided, sets the value. If omitted, returns the value.
     * @returns {*} The current value when called as getter
     */
    val(value) {
        if (arguments.length === 0) {
            // Getter
            if (this._pending_value !== null) {
                return this._transform_value(this._pending_value);
            }
            return this._get_value();
        }

        // Setter
        if (this._is_ready) {
            this._set_value(value);
            this._pending_value = null;
        } else {
            this._pending_value = value;
        }
        this.trigger('val', value);
    }

    /**
     * Mark the component as ready and apply any buffered value.
     * Call this in on_ready() when the component is fully initialized.
     *
     * For components with async initialization (e.g., waiting for external
     * libraries like Quill or TomSelect), call this AFTER initialization
     * completes, not at the start of on_ready().
     */
    _mark_ready() {
        this._is_ready = true;
        if (this._pending_value !== null) {
            this._set_value(this._pending_value);
            this._pending_value = null;
        }
    }

    /**
     * Get the current value from DOM/component.
     * Concrete classes MUST implement this method.
     *
     * @returns {*} The current value
     */
    _get_value() {
        throw new Error(`${this.constructor.name} must implement _get_value()`);
    }

    /**
     * Set the value on DOM/component.
     * Concrete classes MUST implement this method.
     *
     * @param {*} value - The value to set
     */
    _set_value(value) {
        throw new Error(`${this.constructor.name} must implement _set_value()`);
    }

    /**
     * Transform a pending value for getter return.
     * Override this if the value needs transformation when returned.
     *
     * Example: Checkbox_Input transforms boolean/string to checked_value/unchecked_value
     *
     * @param {*} value - The pending value to transform
     * @returns {*} The transformed value
     */
    _transform_value(value) {
        return value;
    }

    /**
     * Seed - Fill with random test data (optional)
     * Subclasses MAY implement this method
     */
    async seed() {
        // Optional - widgets can override if they support seeding
    }
}
