/**
 * Share_Document_Modal_Form
 *
 * The Share-Document modal body: a checkbox table of ALL of a client's active
 * contacts (with their portal invite status shown as context) plus an optional
 * message textarea. Unlike the Add-Member form, NO rows are disabled - a document
 * can be shared with members, invited contacts, or not-yet-invited contacts alike
 * (the controller invites the latter automatically).
 *
 * State-only component (no on_load): the parent modal class passes the already-fetched
 * contacts in via this.args.contacts and reads the selection + message back through
 * vals(). All UI state is incremental DOM mutation, so handlers live in on_render().
 */
class Share_Document_Modal_Form extends Component {

    on_render() {
        // Per-row checkbox: keep the select-all + counter in sync.
        this.$sid('rows').on('change', '.Share_Document_Modal_Form__check', () => {
            this._sync_select_all();
            this._update_count();
        });

        // Clicking anywhere on a row toggles its checkbox.
        this.$sid('rows').on('click', '.Share_Document_Modal_Form__row', (e) => {
            const $check = $(e.currentTarget).find('.Share_Document_Modal_Form__check');
            if ($(e.target).is($check)) return; // clicked the checkbox itself
            $check.prop('checked', !$check.prop('checked')).trigger('change');
        });

        // Select-all / select-none over the currently VISIBLE rows.
        this.$sid('select-all').on('change', (e) => {
            const checked = $(e.currentTarget).prop('checked');
            this._all_checks().each((i, el) => {
                const $row = $(el).closest('.Share_Document_Modal_Form__row');
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
     * All contact checkboxes.
     *
     * @returns {jQuery}
     */
    _all_checks() {
        return this.$sid('rows').find('.Share_Document_Modal_Form__check');
    }

    /**
     * Update the "N selected" counter.
     */
    _update_count() {
        const count = this._all_checks().filter(':checked').length;
        this.$sid('count').text(`${count} selected`);
    }

    /**
     * Reflect the select-all checkbox state from the visible rows.
     */
    _sync_select_all() {
        const $visible = this._all_checks().filter((i, el) => {
            return $(el).closest('.Share_Document_Modal_Form__row').is_visible();
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

        this.$sid('rows').find('.Share_Document_Modal_Form__row').each((i, el) => {
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
     * Selected contact ids + the optional message.
     *
     * @returns {{contact_ids: number[], message: string}}
     */
    vals() {
        const contact_ids = [];
        this._all_checks().filter(':checked').each((i, el) => {
            contact_ids.push(int($(el).data('contact-id')));
        });
        return { contact_ids, message: str(this.$sid('message').val()).trim() };
    }
}
