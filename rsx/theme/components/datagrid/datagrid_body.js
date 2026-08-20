/**
 * DataGrid_Body Component
 *
 * Handles data loading and rendering for the tbody portion of a datagrid.
 * Receives data_source and query parameters from parent DataGrid_Abstract.
 *
 * JavaScript Responsibilities:
 * - Loads data from Ajax endpoint in on_load()
 * - Updates this.data with rows, loading state, and metadata
 * - Minimal logic - parent handles pagination/sorting/filtering state
 */
class DataGrid_Body extends Component {
    // Initialize data before first render
    on_create() {
        let that = this;

        // Initialize data state immediately so template can render
        that.data.rows = [];
        that.data.loading = true;
        that.data.is_empty = false;
        that.data.loaded = false;
        that.data.total = 0;
        that.data.total_pages = 0;
    }

    // Load data from Ajax endpoint
    async on_load() {
        let that = this;

        if (!that.args.data_source) {
            console.error('DataGrid_Body requires args.data_source');
            return;
        }

        // Set loading state
        that.data.loading = true;

        console.log('Loading ', that.args);

        const response = await Ajax.call(that.args.data_source, {
            page: that.args.page || 1,
            per_page: that.args.per_page || 15,
            sort: that.args.sort || null,
            order: that.args.order || 'asc',
            filter: that.args.filter || '',
        });

        // Update data with response
        that.data.loading = false;
        that.data.loaded = true;
        that.data.rows = response.records;
        that.data.page = response.page;
        that.data.per_page = response.per_page;
        that.data.total = response.total;
        that.data.total_pages = response.total_pages;
        that.data.sort = response.sort;
        that.data.order = response.order;
        that.data.is_empty = response.records.length === 0;
    }

    // Attach row click handler and clear filter button after render
    on_render() {
        let that = this;

        // Update tbody classes based on state
        that.$.removeClass('is-loading is-empty');
        if (that.data.loading && that.data.rows.length === 0) {
            that.$.addClass('is-loading');
        } else if (that.data.is_empty) {
            that.$.addClass('is-empty');
        }

        // Step 1: Wrap cells in data-href rows with anchor tags
        that.$.find('tr[data-href]').each(function () {
            let $row = $(this);
            let href = $row.attr('data-href');

            $row.find('td').each(function () {
                let $col = $(this);
                // Skip if cell already contains interactive elements
                if ($col.find('a, button, input, select, textarea').length > 0) {
                    return;
                }
                // Wrap entire cell contents in an anchor (preserve DOM nodes for component lifecycle)
                let $anchor = $('<a>', {
                    href: href,
                    class: 'DataGrid_Abstract__row-link',
                });
                // Move existing child nodes into anchor (preserves components and their state)
                $col.contents().appendTo($anchor);
                // Add anchor to cell
                $col.append($anchor);
            });
        });

        // Step 2: Find all cells with single anchor as only child and apply full-width styling
        that.$.find('td').each(function () {
            let $col = $(this);
            let $children = $col.children();

            // Check if cell contains exactly one direct child that is an anchor
            // AND no other content (text nodes outside the anchor)
            if ($children.length === 1 && $children.first().is('a')) {
                let $anchor = $children.first();
                let cellText = $col.text().trim();
                let anchorText = $anchor.text().trim();

                // Only apply full-link if anchor is the sole content (no surrounding text)
                if (cellText === anchorText) {
                    $col.addClass('DataGrid_Abstract__cell-full-link');
                } else {
                    // Cell has text + anchor (like rendered action text), treat as mixed content
                    $col.addClass('DataGrid_Abstract__cell-text-only');
                }
            }
            // Check if cell contains only text (no child elements)
            else if ($children.length === 0) {
                // Add class to apply vertical padding to text-only cells
                $col.addClass('DataGrid_Abstract__cell-text-only');
            }
        });

        // Attach clear filter button handler
        const $clear_btn = that.$sid('clear_filter_btn');
        if ($clear_btn && $clear_btn.length > 0) {
            $clear_btn.on('click', function (e) {
                e.preventDefault();
                // Trigger custom event for parent to handle
                that.trigger('datagrid:clear_filter');
            });
        }
    }
}
