/**
 * Add_Portal_Members_Modal_Form
 *
 * The Add-Member modal body: a checkbox table of ALL of a client's contacts with
 * their portal invite status (Blank / Invited / Accepted). Already-accepted members
 * render checked + disabled + grayed; Blank and Invited rows are selectable. A live
 * filter shows/hides rows by name/email and a "N selected" counter tracks the
 * enabled-checked rows.
 *
 * State-only component (no on_load): the parent modal class passes the
 * already-fetched contacts in via this.args.contacts and reads the selection back
 * through vals(). All UI state is incremental DOM mutation, so handlers live in
 * on_render() (the template is rendered once).
 */
class Add_Portal_Members_Modal_Form extends Component {

    on_render() {
        // Per-row checkbox: keep the select-all + counter in sync.
        this.$sid('rows').on('change', '.Add_Portal_Members_Modal_Form__check', () => {
            this._sync_select_all();
            this._update_count();
        });

        // Clicking anywhere on a row toggles its checkbox (members are disabled = no-op).
        this.$sid('rows').on('click', '.Add_Portal_Members_Modal_Form__row', (e) => {
            const $check = $(e.currentTarget).find('.Add_Portal_Members_Modal_Form__check');
            if ($check.is(':disabled')) return;
            if ($(e.target).is($check)) return; // clicked the checkbox itself - let it toggle natively
            $check.prop('checked', !$check.prop('checked')).trigger('change');
        });

        // Select-all / select-none over the currently VISIBLE, enabled rows.
        this.$sid('select-all').on('change', (e) => {
            const checked = $(e.currentTarget).prop('checked');
            this._selectable_checks().each((i, el) => {
                const $row = $(el).closest('.Add_Portal_Members_Modal_Form__row');
                if ($row.is_visible()) {
                    $(el).prop('checked', checked);
                }
            });
            this._update_count();
        });

        // Live filter by name/email.
        this.$sid('filter').on('input', debounce(() => this._apply_filter(), 150));

        this._update_count();
        this._sync_select_all();
    }

    /**
     * Enabled (selectable) checkboxes - members are disabled and excluded.
     *
     * @returns {jQuery}
     */
    _selectable_checks() {
        return this.$sid('rows').find('.Add_Portal_Members_Modal_Form__check:not(:disabled)');
    }

    /**
     * Update the "N selected" counter (enabled-checked rows only).
     */
    _update_count() {
        const count = this._selectable_checks().filter(':checked').length;
        this.$sid('count').text(`${count} selected`);
    }

    /**
     * Reflect the select-all checkbox state from the visible selectable rows.
     */
    _sync_select_all() {
        const $visible = this._selectable_checks().filter((i, el) => {
            return $(el).closest('.Add_Portal_Members_Modal_Form__row').is_visible();
        });
        const total = $visible.length;
        const checked = $visible.filter(':checked').length;

        const $all = this.$sid('select-all');
        $all.prop('checked', total > 0 && checked === total);
        $all.prop('indeterminate', checked > 0 && checked < total);
    }

    /**
     * Show/hide rows by the filter text, then re-sync the counter helpers.
     */
    _apply_filter() {
        const term = str(this.$sid('filter').val()).trim().toLowerCase();
        let visible = 0;

        this.$sid('rows').find('.Add_Portal_Members_Modal_Form__row').each((i, el) => {
            const $row = $(el);
            const haystack = str($row.data('search'));
            const match = term === '' || haystack.indexOf(term) !== -1;
            $row.toggleClass('d-none', !match);
            if (match) visible++;
        });

        this.$sid('no-results').toggleClass('d-none', visible > 0);
        this._sync_select_all();
    }

    /**
     * Selected contact ids (enabled-checked only - never includes existing members).
     *
     * @returns {{contact_ids: number[]}}
     */
    vals() {
        const contact_ids = [];
        this._selectable_checks().filter(':checked').each((i, el) => {
            contact_ids.push(int($(el).data('contact-id')));
        });
        return { contact_ids };
    }
}
