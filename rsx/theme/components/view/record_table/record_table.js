/**
 * Record_Table
 *
 * Compact record list table. Wires whole-row navigation for <tr data-href>
 * rows, ignoring clicks on real interactive elements inside a row.
 * See record_table.jqhtml.
 */
class Record_Table extends Component {
    on_render() {
        // Idempotent delegated row navigation (on_render may re-fire).
        this.$.off('click.recordtable').on('click.recordtable', 'tr[data-href]', (e) => {
            // Let real interactive elements handle their own clicks.
            if ($(e.target).closest('a, button, input, select, label, .no-row-nav').length) {
                return;
            }
            const href = $(e.currentTarget).attr('data-href');
            if (href) {
                Spa.dispatch(href);
            }
        });
    }
}
