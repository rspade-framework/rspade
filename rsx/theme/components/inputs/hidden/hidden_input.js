/**
 * Hidden_Input - Hidden input for passing values through forms
 *
 * Extends Form_Input_Abstract. The component element IS the input (tag="input" type="hidden").
 *
 * $name - Field name (required)
 *
 * val() accepts/returns: string
 */
class Hidden_Input extends Form_Input_Abstract {
    _get_value() {
        return this.$.val();
    }

    _set_value(value) {
        this.$.val(value || '');
    }

    on_ready() {
        this._mark_ready();
    }
}
