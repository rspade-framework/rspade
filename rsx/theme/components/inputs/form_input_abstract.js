/**
 * Form_Input_Abstract - Base class for all form input widgets
 *
 * This class implements the template method pattern, handling:
 * - Pre-initialization value buffering
 * - val() getter/setter logic
 * - Event triggers (trigger('val') on all changes)
 * - Setting data-name attribute from $name arg for form integration
 *
 * CORE CONTRACT: TIMING INDIFFERENCE
 *
 * Callers must never care about component lifecycle timing.
 *
 * .val(value) MUST produce identical results whether called:
 * - Before the component initializes (buffered, applied at _mark_ready())
 * - After the component initializes (applied immediately via _set_value())
 * - At any point during the component's lifecycle
 *
 * This is non-negotiable. If a caller needs to use `await component.ready()`
 * or timing tricks to make val() work, THE COMPONENT IS BROKEN.
 *
 * CONCRETE CLASS CONTRACT:
 *
 * REQUIRED - Implement these methods:
 * - _get_value()        Return the current DOM/component value
 * - _set_value(value)   Set the DOM/component value
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
 * NAME ATTRIBUTE:
 * - Input components take $name directly: <Text_Input $name="title" />
 * - Base class sets data-name on component root for form integration
 * - Form_Field reads name from child input's data-name for error display
 *
 * All widgets automatically have .Form_Input_Abstract class via jqhtml.
 *
 * See: rsx/theme/components/inputs/CLAUDE.md for implementation details
 */
class Form_Input_Abstract extends Component {
    /**
     * Initialize buffering state and set data-name attribute.
     * Concrete classes MUST call super.on_create() if they override this.
     */
    on_create() {
        this._pending_value = null;
        this._is_ready = false;

        // Set data-name attribute from $name arg for form integration
        // This allows Rsx_Form to find and collect values by name
        if (this.args.name) {
            this.$.attr('data-name', this.args.name);
        }
    }

    /**
     * val() - Get or set the current value
     *
     * This method handles all buffering and event logic. Concrete classes
     * should NOT override this method. Instead, implement _get_value()
     * and _set_value().
     *
     * @param {*} [value] - If provided, sets the value. If omitted, returns the value.
     * @returns {*} The current value when called as getter
     */
    val(value) {
        if (arguments.length === 0) {
            // Getter
            if (this._pending_value !== null) {
                return this._pending_value;
            }
            return this._get_value();
        } else {
            // Setter
            if (this._is_ready) {
                this._set_value(value);
                this._pending_value = null;
            } else {
                this._pending_value = value;
            }
            this.trigger('val', value);
        }
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
            this.trigger('val', this._pending_value);
            this._pending_value = null;
        } else {
            this.trigger('val', this._get_value());
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
     * TIMING INDIFFERENCE: This method must work identically whether called
     * during initial value application (via _mark_ready()) or later (via
     * direct val() calls after the component is ready). If your implementation
     * relies on initialization state or timing, you've broken the contract.
     *
     * @param {*} value - The value to set
     */
    _set_value(value) {
        throw new Error(`${this.constructor.name} must implement _set_value()`);
    }
}
