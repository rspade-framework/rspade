/**
 * Users_DataGrid Component
 *
 * Pagination, sorting, filtering and selection all come from DataGrid_Abstract; what is
 * here is the footer Export action.
 */
class Users_DataGrid extends DataGrid_Abstract {
    static record_noun_plural = 'users';

    on_render() {
        // Export is the ONLY footer action here, and it is permission-gated in the template.
        // Without that permission the menu would open on nothing, so the button goes. This
        // is formatting of the grid's own markup: on_render(), so the cached paint is right
        // too, and every render re-checks it.
        if (this.$.find('.DataGrid_Abstract__footer-actions .dropdown-item').length === 0) {
            this.$.find('.DataGrid_Abstract__footer-actions').remove();
        }
    }

    /**
     * Footer mass-action dispatch.
     *
     * @param {string} action - data-action of the clicked menu item
     * @param {Object} selection - {mode, ids, total, filter_params}
     */
    async on_footer_action(action, selection) {
        let that = this;

        if (action === 'export') {
            await that.export_selection(selection);
        }
    }

    /**
     * Export everything the selection covers as a CSV download. The selection is deliberately
     * NOT cleared: an export reads, it does not consume.
     *
     * @param {Object} selection - payload from on_footer_action()
     */
    async export_selection(selection) {
        const result = await Frontend_Settings_User_Management_Controller.export_csv({
            mode: selection.mode,
            ids: selection.ids,
            filter_params: selection.filter_params,
        });

        trigger_file_download(result.csv, result.filename);

        Flash_Alert.success('Exported ' + result.count + ' user' + (result.count === 1 ? '' : 's'));
    }
}
