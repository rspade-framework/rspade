/**
 * Checkbox_Multiselect_Input - Multi-select checkbox list widget
 *
 * Returns/accepts array of IDs for checked items.
 */
class Checkbox_Multiselect_Input extends Form_Input_Abstract {
    _get_value() {
        const selected = [];
        this.$sid('checkbox_list').find('.multiselect-checkbox:checked').each(function() {
            selected.push(int($(this).val()));
        });
        return selected;
    }

    _set_value(values) {
        // Convert all values to integers for consistent comparison (handles string IDs like "3")
        const ids = is_array(values) ? values.map(v => int(v)) : [];
        this.$sid('checkbox_list').find('.multiselect-checkbox').each(function() {
            const checkbox_id = int($(this).val());
            $(this).prop('checked', ids.includes(checkbox_id));
        });
    }

    on_ready() {
        this._mark_ready();

        const that = this;
        this.$sid('checkbox_list').on('change', '.multiselect-checkbox', function() {
            const value = that.val();
            that.trigger('input', value);
            that.trigger('val', value);
        });
    }
}
