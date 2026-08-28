/**
 * Pin_Input
 *
 * One named value - a short numeric code - presented as N single-character boxes.
 * See pin_input.jqhtml for the usage and the interaction rules.
 *
 * The value contract is the base class's: _get_value() joins the boxes, _set_value()
 * distributes a string across them, and _notify_input() announces every user edit.
 * val() itself is never overridden.
 */
class Pin_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();
        this.pin_length = this.args.length ? int(this.args.length) : 6;
    }

    _get_value() {
        let pin = '';
        this._$boxes().each(function () {
            pin += $(this).val() || '';
        });
        return pin;
    }

    _set_value(value) {
        const digits = str(value ?? '').replace(/[^0-9]/g, '');
        this._$boxes().each(function (i) {
            $(this).val(digits[i] || '');
        });
    }

    on_render() {
        const that = this;

        // The DOM is self-contained, so the input can accept a value from here on.
        this._mark_ready();

        this._$boxes().each(function () {
            const $box = $(this);
            const index = int($box.attr('data-index'));

            // on_render can fire more than once and rebuilds the DOM, so handlers are
            // bound fresh each time onto the fresh nodes.
            $box.on('input', function () {
                const raw = str($box.val());
                const numeric = raw.replace(/[^0-9]/g, '');
                if (numeric !== raw) {
                    $box.val(numeric);
                }

                // Several digits at once (a paste that landed in one box): spread them.
                if (numeric.length > 1) {
                    that._distribute(numeric, index);
                } else if (numeric.length === 1 && index < that.pin_length - 1) {
                    that._focus_box(index + 1);
                }

                that._notify_input(that._get_value());
            });

            $box.on('keydown', function (e) {
                if (e.key === 'Backspace' && !$box.val() && index > 0) {
                    // Empty box: clear the previous one and step back to it.
                    e.preventDefault();
                    that._box(index - 1).val('');
                    that._focus_box(index - 1);
                    that._notify_input(that._get_value());
                    return;
                }
                if (e.key === 'ArrowLeft' && index > 0) {
                    e.preventDefault();
                    that._focus_box(index - 1);
                }
                if (e.key === 'ArrowRight' && index < that.pin_length - 1) {
                    e.preventDefault();
                    that._focus_box(index + 1);
                }
            });

            $box.on('paste', function (e) {
                e.preventDefault();
                const pasted = (e.originalEvent || e).clipboardData.getData('text');
                that._distribute(str(pasted).replace(/[^0-9]/g, ''), index);
                that._notify_input(that._get_value());
            });

            // Selecting on focus makes replacing a digit a single keystroke.
            $box.on('focus', function () {
                $box[0].select();
            });
        });
    }

    /** The boxes, in order. */
    _$boxes() {
        return this.$sid('boxes').find('.Pin_Input__box');
    }

    /** One box by index. */
    _box(index) {
        return this.$sid('boxes').find(`.Pin_Input__box[data-index="${index}"]`);
    }

    /**
     * Write a run of digits starting at a box, then park the caret after them.
     *
     * @param {string} digits
     * @param {number} start_index
     */
    _distribute(digits, start_index) {
        if (!digits) {
            return;
        }

        for (let i = 0; i < digits.length && (start_index + i) < this.pin_length; i++) {
            this._box(start_index + i).val(digits[i]);
        }

        this._focus_box(Math.min(start_index + digits.length, this.pin_length - 1));
    }

    /**
     * Focus a box and select whatever is in it.
     *
     * @param {number} index
     */
    _focus_box(index) {
        if (index < 0 || index >= this.pin_length) {
            return;
        }
        const $box = this._box(index);
        if ($box.exists()) {
            $box[0].focus();
            $box[0].select();
        }
    }
}
