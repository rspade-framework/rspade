/**
 * Invoices_DataGrid - quick filters (status incl. Overdue, client) in the card header.
 */
class Invoices_DataGrid extends DataGrid_Abstract {
    static allowed_filters = ['status', 'client_id'];

    static record_noun_plural = 'invoices';

    on_render() {
        // The quick filters are this grid's own header markup, so they are set and bound in
        // on_render() - re-run on every render, hence the namespaced .off() before .on().
        this._bind_quick_filter('status_filter', 'status');
        if (this.args.client_options) {
            this._bind_quick_filter('client_filter', 'client_id');
        }
    }

    /**
     * Point one header <select> at one whitelisted filter key, set FROM the grid's state
     * (default_filters and the URL-hash restore have already run in on_create()).
     *
     * @param {string} sid - $sid of the <select> in the card header
     * @param {string} key - filter key, must be in allowed_filters
     */
    _bind_quick_filter(sid, key) {
        let that = this;

        const $select = that.$sid(sid);

        $select.val(str(that.get_custom_filter(key) ?? ''));

        $select.off('change.idg').on('change.idg', function () {
            const $element = $(this);
            that.set_custom_filter(key, $element.val() || null);
        });
    }
}
