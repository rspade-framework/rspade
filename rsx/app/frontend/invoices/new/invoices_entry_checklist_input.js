/**
 * Invoices_Entry_Checklist_Input - ticked entry ids as one form value, with the running total
 * of the ticked entries' amounts shown under the list.
 *
 * A click anywhere on a row toggles its checkbox; the header checkbox ticks or clears every
 * row. Every user change is announced through _notify_input() and repaints the total.
 */
class Invoices_Entry_Checklist_Input extends Form_Input_Abstract {
    _get_value() {
        const ids = [];
        this.$sid('rows').find('input[type="checkbox"]:checked').each(function () {
            ids.push(int($(this).val()));
        });
        return ids;
    }

    _set_value(value) {
        const ids = is_array(value) ? value.map((v) => int(v)) : [];
        this.$sid('rows').find('input[type="checkbox"]').each(function () {
            const $box = $(this);
            $box.prop('checked', ids.includes(int($box.val())));
        });
        this._paint_selection();
    }

    on_render() {
        // Namespaced and idempotent: on_render() re-fires on every render.
        this.$.off('.iecl');

        this.$.on('change.iecl', 'tbody input[type="checkbox"]', () => this._user_changed());

        this.$.on('click.iecl', '.Invoices_Entry_Checklist_Input__row', (e) => {
            if ($(e.target).is('input, a, button, label')) return;
            const $box = $(e.currentTarget).find('input[type="checkbox"]');
            $box.prop('checked', !$box.prop('checked'));
            this._user_changed();
        });

        this.$sid('all').off('change.iecl').on('change.iecl', (e) => {
            this.$sid('rows').find('input[type="checkbox"]').prop('checked', $(e.currentTarget).prop('checked'));
            this._user_changed();
        });

        this._mark_ready();
        this._paint_selection();
    }

    _user_changed() {
        this._paint_selection();
        this._notify_input(this._get_value());
    }

    /** The selected count and total, the row highlight and the header checkbox's state. */
    _paint_selection() {
        const ids = this._get_value();
        const entries = this.args.entries || [];
        const picked = entries.filter((e) => ids.includes(int(e.id)));

        this.$sid('count').text(picked.length);
        this.$sid('total').text(Rsx_Money.sum(picked.map((e) => e.amount)).format());

        this.$sid('rows').find('.Invoices_Entry_Checklist_Input__row').each(function () {
            const $row = $(this);
            $row.toggleClass('Invoices_Entry_Checklist_Input__row--selected', $row.find('input').prop('checked'));
        });

        const $all = this.$sid('all');
        $all.prop('checked', entries.length > 0 && picked.length === entries.length);
        $all.prop('indeterminate', picked.length > 0 && picked.length < entries.length);
    }
}
