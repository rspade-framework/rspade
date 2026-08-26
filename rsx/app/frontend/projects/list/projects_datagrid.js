/**
 * Projects_DataGrid - quick filters (status, priority) in the card header.
 *
 * The list opens on Active projects: a project list is a list of work in flight, and the
 * completed/cancelled backlog outgrows it. Every other status stays one select away, and
 * the choice rides the URL hash so a shared link reopens on what the sender was looking at.
 */
class Projects_DataGrid extends DataGrid_Abstract {
    static allowed_filters = ['status', 'priority'];

    static default_filters = { status: Project_Model.STATUS_ACTIVE };

    static record_noun_plural = 'projects';

    on_ready() {
        super.on_ready();

        this._bind_quick_filter('status_filter', 'status');
        this._bind_quick_filter('priority_filter', 'priority');
    }

    /**
     * Point one header <select> at one whitelisted filter key.
     *
     * The grid's state is authoritative in both directions of the boot: default_filters and
     * the URL-hash restore have both already run in on_create(), so the widget is set FROM
     * the state - never the other way round, and never from a `selected` attribute in markup.
     *
     * @param {string} sid - $sid of the <select> in the card header
     * @param {string} key - filter key, must be in allowed_filters
     */
    _bind_quick_filter(sid, key) {
        let that = this;

        const $select = that.$sid(sid);

        $select.val(str(that.get_custom_filter(key) ?? ''));

        $select.on('change', function () {
            const $element = $(this);
            that.set_custom_filter(key, $element.val() || null);
        });
    }

    /**
     * Footer mass-action dispatch. The selection payload already describes the SET the user
     * means - additive ids, subtractive exclusions, or the whole filtered result - so it goes
     * to the server verbatim, filters included.
     *
     * @param {string} action - data-action of the clicked menu item
     * @param {Object} selection - {mode, ids, total, filter_params}
     */
    async on_footer_action(action, selection) {
        let that = this;

        if (action === 'delete') {
            await that._delete_selection(selection);
            return;
        }

        if (action === 'export') {
            await that.export_selection(selection);
        }
    }

    /**
     * Confirm, then soft-delete everything the selection covers.
     *
     * @param {Object} selection - payload from on_footer_action()
     */
    async _delete_selection(selection) {
        let that = this;

        const size = that.selection_size(selection);

        const confirmed = await Modal.confirm(
            'Delete Projects',
            'Delete ' + size + ' project' + (size === 1 ? '' : 's') + '?'
        );

        if (!confirmed) {
            return;
        }

        const result = await Frontend_Projects_Controller.bulk_delete({
            mode: selection.mode,
            ids: selection.ids,
            filter_params: selection.filter_params,
        });

        Flash_Alert.success(
            'Deleted ' + result.deleted + ' project' + (result.deleted === 1 ? '' : 's')
        );

        that._clear_selection();
        that.reload();
    }

    /**
     * Export everything the selection covers as a CSV download.
     *
     * Public because the page header's Export button calls it with a whole-filtered-set
     * payload - "export what I'm looking at" is the same operation as the footer action.
     * The selection is deliberately NOT cleared: an export reads, it does not consume.
     *
     * @param {Object} selection - payload from on_footer_action()
     */
    async export_selection(selection) {
        const result = await Frontend_Projects_Controller.export_csv({
            mode: selection.mode,
            ids: selection.ids,
            filter_params: selection.filter_params,
        });

        trigger_file_download(result.csv, result.filename);

        Flash_Alert.success('Exported ' + result.count + ' project' + (result.count === 1 ? '' : 's'));
    }

    /**
     * A selection payload covering the ENTIRE filtered set - what the page header's Export
     * button exports, regardless of which rows are ticked.
     *
     * @returns {Object}
     */
    whole_set_selection() {
        let that = this;

        return {
            mode: 'all',
            ids: [],
            total: that.state.total,
            filter_params: that._get_current_filter_params(),
        };
    }
}
