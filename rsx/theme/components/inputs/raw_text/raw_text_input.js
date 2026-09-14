/**
 * Raw_Text_Input - textarea widget for a Raw_Text column.
 *
 * val() gets and sets a Raw_Text INSTANCE, never a string. ACCEPTS names the type, so a
 * column whose $text_types entry is something else - or a plain varchar with no type at
 * all - cannot be wired here.
 *
 * There is no $max_length. A TEXT column has no varchar ceiling for
 * Model.field_length() to report, and length is a server rule regardless.
 */
class Raw_Text_Input extends Form_Input_Abstract {
    // A NAME, not a class reference: this component is defined before rsx/lib/ in the
    // bundle, so naming the class directly would read Raw_Text before its declaration is
    // initialised. Form_Input_Abstract resolves the name through the manifest at the
    // moment a value arrives.
    static ACCEPTS = 'Raw_Text';

    /**
     * @returns {Raw_Text|null}
     */
    _get_value() {
        return Raw_Text.from_editor(this.$sid('input').val());
    }

    /**
     * @param {Raw_Text|null} value
     */
    _set_value(value) {
        // Editing always works on the raw form - for this type the raw form IS the text.
        this.$sid('input').val(value === null || value === undefined ? '' : value.to_storage());
    }

    on_ready() {
        this._mark_ready();

        const that = this;
        this.$sid('input').on('input', function () {
            that._notify_input(that.val());
        });
    }

    async seed() {
        if (this.args.seeder) {
            this.val(Raw_Text.from_editor('Test ' + this.args.seeder));
        }
    }
}
