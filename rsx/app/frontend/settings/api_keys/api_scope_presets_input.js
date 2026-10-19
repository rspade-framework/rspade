/**
 * Api_Scope_Presets_Input
 *
 * See api_scope_presets_input.jqhtml for the argument contract.
 *
 * The value is an array of preset NAMES - string values, which is why this is its own input
 * rather than <Checkbox_Multiselect_Input> (that one coerces every id to an integer, because
 * its ids are record ids).
 */
class Api_Scope_Presets_Input extends Form_Input_Abstract {
    _get_value() {
        const names = [];

        this.$sid('list').find('.Api_Scope_Presets_Input__checkbox:checked').each(function () {
            const $checkbox = $(this);
            names.push($checkbox.val());
        });

        return names;
    }

    _set_value(value) {
        const names = is_array(value) ? value : [];

        this.$sid('list').find('.Api_Scope_Presets_Input__checkbox').each(function () {
            const $checkbox = $(this);
            $checkbox.prop('checked', names.includes($checkbox.val()));
        });
    }

    on_render() {
        // The user's edit is announced from the first paint. Namespaced and idempotent:
        // on_render() re-fires on every render and the list element is rebuilt each time.
        const that = this;
        this.$sid('list').off('change.api_scope_presets').on('change.api_scope_presets', '.Api_Scope_Presets_Input__checkbox', function () {
            that._notify_input(that.val());
        });
    }

    on_ready() {
        // The earliest moment a write would stick: the checkboxes exist.
        this._mark_ready();
    }
}
