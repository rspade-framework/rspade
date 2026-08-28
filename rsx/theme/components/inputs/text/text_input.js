class Text_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();

        // Only require $max_length when Text_Input is used directly, not subclasses
        if (this.constructor === Text_Input && this.args.max_length === undefined) {
            console.error(
                `Text_Input with $name="${this.args.name}" requires $max_length. ` +
                `Use $max_length=Model_Name.field_length('column_name') for database-driven limits, ` +
                `a numeric value for custom limits, or -1 for unlimited.`
            );
        }
    }

    _get_value() {
        return this.$sid('input').val();
    }

    _set_value(value) {
        this.$sid('input').val(value || '');
    }

    on_ready() {
        this._mark_ready();

        const that = this;
        this.$sid('input').on('input', function() {
            // _notify_input() is the ONE way an input announces a USER change: it
            // fires 'input' then 'val', in that order. Never hand-trigger the pair.
            that._notify_input(that.val());
        });
    }

    async seed() {
        if (this.args.seeder) {
            // TODO: Implement Rsx_Random_Values endpoint
            let value = 'Test ' + (this.args.seeder || 'Value');
            this.val(value);
        }
    }
}
