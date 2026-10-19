/**
 * Tasks_DataGrid - quick filters (status, priority) in the card header.
 *
 * The status select carries one composite value, 'open', alongside the plain status ids:
 * a task list is a work queue, so it opens on everything not yet finished or abandoned.
 * The composite is expanded server-side in tasks_datagrid.php - the client only ever
 * sends the word.
 */
class Tasks_DataGrid extends DataGrid_Abstract {
    static allowed_filters = ['status_filter', 'priority'];

    static default_filters = { status_filter: 'open' };

    static record_noun_plural = 'tasks';

    on_render() {
        // The quick filters are this grid's own header markup, so they are set and bound in
        // on_render() - re-run on every render, hence the namespaced .off() before .on().
        this._bind_quick_filter('status_filter', 'status_filter');
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

        $select.off('change.tdg').on('change.tdg', function () {
            const $element = $(this);
            that.set_custom_filter(key, $element.val() || null);
        });
    }
}
