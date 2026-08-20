/**
 * Text_Input - Reference implementation using template method pattern
 *
 * Reference implementation for form_input_abstract_12_29.txt migration guide.
 * Shows the minimal implementation required for a simple input component.
 *
 * Key points:
 * - No on_create() needed (base class handles _pending_value/_is_ready)
 * - No val() needed (base class handles it)
 * - Just implement _get_value(), _set_value(), and on_ready()
 * - Call _mark_ready() in on_ready() to apply buffered values
 * - Trigger both 'input' and 'val' on user interaction
 */
class Text_Input extends Form_Input_Abstract {
    /**
     * Return the current input value.
     * Called by base class val() getter.
     */
    _get_value() {
        return this.$sid('input').val();
    }

    /**
     * Set the input value.
     * Called by base class val() setter and _mark_ready().
     */
    _set_value(value) {
        this.$sid('input').val(value || '');
    }

    /**
     * Initialize component after render.
     * - Call _mark_ready() to apply any buffered value
     * - Setup user interaction events
     */
    on_ready() {
        // Apply any buffered value from pre-ready val() calls
        this._mark_ready();

        // Trigger events on user interaction
        const that = this;
        this.$sid('input').on('input', function() {
            const value = that.val();
            that.trigger('input', value);  // User interaction only
            that.trigger('val', value);    // All changes
        });
    }

    /**
     * Seed - Fill with random test data
     */
    async seed() {
        if (this.args.seeder) {
            // TODO: Implement Rsx_Random_Values endpoint
            let value = 'Test ' + (this.args.seeder || 'Value');
            this.val(value);
        }
    }
}
