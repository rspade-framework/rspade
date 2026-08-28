/**
 * Tag_List_Input
 *
 * A list of short strings as ONE value. See tag_list_input.jqhtml for usage.
 *
 * The value contract is the base class's: _get_value() reports the entered tags,
 * _set_value() takes an array, and _notify_input() announces every user edit. val() is
 * never overridden.
 *
 * The rows are built in JavaScript, and a render REBUILDS the DOM - so the rows are
 * drawn from STATE in on_render(), and every user edit writes back to that state.
 * Anything drawn once imperatively would vanish the next time the component rendered.
 */
class Tag_List_Input extends Form_Input_Abstract {

    on_create() {
        super.on_create();

        // The value, independent of the DOM that happens to be on screen.
        this._tags = [];
    }

    _get_value() {
        // Blank rows are EDITING state, not value: a row the user is halfway through
        // typing must not vanish underneath them, and it must not reach the server.
        return this._tags.map((tag) => str(tag).trim()).filter((tag) => tag !== '');
    }

    _set_value(value) {
        this._tags = is_array(value) ? value.slice() : [];
        this._draw_rows();
    }

    on_render() {
        // The DOM is self-contained, so a value can be applied from here on. If one was
        // buffered, _mark_ready() applies it and draws the rows itself.
        this._mark_ready();

        // Redraw from state on every render - including the renders that had nothing
        // buffered, which is what keeps an already-entered list on screen.
        this._draw_rows();

        const that = this;

        this.$sid('add_btn').on('click', function () {
            that._tags.push('');
            that._draw_rows();
            that.$sid('rows').find('.Tag_List_Input__text').last().focus();
            that._notify_input(that._get_value());
        });

        // Delegated, so rows drawn later are covered without rebinding.
        this.$sid('rows').on('input', '.Tag_List_Input__text', function () {
            that._read_rows();
            that._notify_input(that._get_value());
        });

        this.$sid('rows').on('click', '.Tag_List_Input__remove', function () {
            const index = int($(this).closest('.Tag_List_Input__row').attr('data-index'));

            that._read_rows();
            that._tags.splice(index, 1);
            that._draw_rows();
            that._notify_input(that._get_value());
        });
    }

    /** Pull what is currently typed in the rows back into the value. */
    _read_rows() {
        const tags = [];
        this.$sid('rows').find('.Tag_List_Input__text').each(function () {
            tags.push(str($(this).val()));
        });
        this._tags = tags;
    }

    /**
     * Draw one row per tag, keeping at least one row so there is always somewhere to
     * type.
     */
    _draw_rows() {
        const $rows = this.$sid('rows');
        if (!$rows.exists()) {
            return;
        }

        if (this._tags.length === 0) {
            this._tags = [''];
        }

        $rows.empty();

        for (let i = 0; i < this._tags.length; i++) {
            const $row = $(
                '<div class="Tag_List_Input__row input-group mb-2">' +
                    '<input type="text" class="form-control Tag_List_Input__text">' +
                    '<button class="btn btn-danger Tag_List_Input__remove" type="button">' +
                        '<i class="bi bi-trash"></i>' +
                    '</button>' +
                '</div>'
            );

            $row.attr('data-index', i);
            $row.find('.Tag_List_Input__text')
                .val(this._tags[i] ?? '')
                .attr('placeholder', this.args.placeholder || '');

            $rows.append($row);
        }
    }
}

