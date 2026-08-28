class Checkbox_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();
        this.checked_value = this.args.checked_value || '1';
        this.unchecked_value = this.args.unchecked_value || '0';
    }

    _get_value() {
        const is_checked = this.$sid('input').prop('checked');
        return is_checked ? this.checked_value : this.unchecked_value;
    }

    _set_value(value) {
        let should_check = false;
        if (typeof value === 'boolean') {
            should_check = value;
        } else if (value === this.checked_value || value === '1' || value === 1 || value === true) {
            should_check = true;
        }
        this.$sid('input').prop('checked', should_check);
    }

    on_ready() {
        this._mark_ready();

        // Connect label clicks to checkbox
        const $input = this.$sid('input');
        const $label = this.$sid('label');

        if ($label.exists()) {
            const input_id = $input.attr('id');
            $label.attr('for', input_id);
        }

        // Trigger events on user interaction
        const that = this;
        $input.on('change', function() {
            that._notify_input(that.val());
        });
    }

    async seed() {
        // Randomly check or uncheck
        this.val(Math.random() > 0.5);
    }
}
