class Select_With_Description_Input extends Form_Input_Abstract {
    _get_value() {
        return this.$sid('input').val();
    }

    _set_value(value) {
        this.$sid('input').val(value ?? '');
        this._update_description();
    }

    on_render() {
        // Namespaced and idempotent: on_render() re-fires on every render.
        const that = this;
        this.$sid('input').off('change.select_with_description').on('change.select_with_description', function() {
            that._update_description();
            that._notify_input(that.val());
        });
    }

    on_ready() {
        this._mark_ready();

        this._update_description();
    }

    _update_description() {
        const value = this.$sid('input').val();
        const description = this.description_map[value] || '';
        this.$sid('description').text(description);
    }
}
