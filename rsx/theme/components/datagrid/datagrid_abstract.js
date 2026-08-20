/**
 * DataGrid_Abstract Component
 *
 * Parent component managing datagrid state (pagination, sorting, filtering).
 * Does NOT use on_load() - delegates data fetching to child DataGrid_Body component.
 *
 * **Architecture**:
 * - Parent (this): Manages state in this.state (page, sort, filter, etc.)
 * - Child (DataGrid_Body): Loads data in on_load() based on this.args
 *
 * **State Management**:
 * - this.state: All mutable component state (since we don't use on_load())
 * - this.data: NOT USED (component doesn't load data)
 * - Template reads from this.state directly
 *
 * **Features**:
 * - Pagination (next/prev/page select)
 * - Sorting (click headers)
 * - Filtering (search input)
 * - URL state synchronization
 *
 * **Usage**:
 * ```html
 * <Contacts_DataGrid $data_source="Frontend_Contacts_Controller.datagrid_fetch" />
 * ```
 *
 * **Required Args**:
 * - `data_source` - Ajax endpoint for fetching data
 *
 * **Optional Args**:
 * - `per_page` - Default rows per page (default: 15)
 * - `sort` - Default sort column (default: null)
 * - `order` - Default sort order (default: 'asc')
 */
class DataGrid_Abstract extends Component {
    // Initialize state before first render
    on_create() {
        let that = this;

        // Default state
        that.state = {
            page: 1,
            per_page: 15,
            sort: null,
            order: 'asc',
            filter: '',
            total: 0,
            total_pages: 0,
        };

        that.default_state = clone(that.state);

        if (!that.args.data_source) {
            console.error('Datagrid ' + that.component_name() + ' requires args.data_source set to an Ajax_Endpoint');
            return;
        }

        // Store defaults for URL state comparison
        if (that.args.per_page) that.state.per_page = that.args.per_page;
        if (that.args.sort) that.state.default_sort = that.args.sort;
        if (that.args.order) that.state.default_order = that.args.order;

        that.state.default_filter = '';

        // Initialize state from URL hash if present, otherwise use defaults
        const hash_page = Rsx.url_hash_get(that._cid + '_page');
        const hash_sort = Rsx.url_hash_get(that._cid + '_sort');
        const hash_order = Rsx.url_hash_get(that._cid + '_order');
        const hash_filter = Rsx.url_hash_get(that._cid + '_filter');

        if (hash_page) that.state.page = int(hash_page);
        if (hash_sort) that.state.sort = hash_sort;
        if (hash_order) that.state.order = hash_order;
        if (hash_filter) that.state.filter = hash_filter;
    }

    // Initialize datagrid after DOM ready
    async on_ready() {
        let that = this;

        that.register_render_callbacks();
        that.register_ready_callbacks();
        that.register_filter_handlers();

        // If hash had a filter value, populate the filter input
        if (that.state.filter) {
            const $filter = that.$sid('filter_input');
            if ($filter && $filter.length > 0) {
                $filter.val(that.state.filter);
            }
        }

        // Measure row height and set fixed tbody height (all in one frame)
        await that.measure_and_set_fixed_height();

        // Fetch the initial page (respects hash state)
        that.load_page(that.state.page);
    }

    // === INTERACTIVE FUNCTIONS ===

    // Sort by specified column, toggling order if already sorted by that column
    sort_by(column) {
        let that = this;

        // Toggle order if clicking same column, otherwise default to asc
        if (that.state.sort === column) {
            that.state.order = that.state.order === 'asc' ? 'desc' : 'asc';
        } else {
            that.state.sort = column;
            that.state.order = 'asc';
        }

        // Update header and reload current page with new sort
        that.update_header();
        that.load_page(that.state.page);
    }

    // Change filter value
    set_filter(value) {
        let that = this;

        that.state.filter = value;

        // Clear the filter input
        const $filter = that.$sid('filter_input');
        if ($filter && $filter.length > 0) {
            $filter.val(value);
        }

        // Reload from page 1
        that.load_page(1);
    }

    // === NON INTERACTIVE FUNCTIONS ===

    // Update header only if sort/order changed
    update_header() {
        let that = this;

        // Track last rendered state
        if (!that._last_header_state) {
            that._last_header_state = {};
        }

        const current = {
            sort: that.state.sort,
            order: that.state.order,
        };

        // Only render if values changed
        if (that._last_header_state.sort !== current.sort || that._last_header_state.order !== current.order) {
            that._last_header_state = current;
            that.sid('datagrid_table_header').render();
        }
    }

    // Update pagination only if values changed
    update_pagination() {
        let that = this;

        // Track last rendered state
        if (!that._last_pagination_state) {
            that._last_pagination_state = {};
        }

        const current = {
            page: that.state.page,
            per_page: that.state.per_page,
            total: that.state.total,
            total_pages: that.state.total_pages,
        };

        // Only render if values changed
        if (
            that._last_pagination_state.page !== current.page ||
            that._last_pagination_state.per_page !== current.per_page ||
            that._last_pagination_state.total !== current.total ||
            that._last_pagination_state.total_pages !== current.total_pages
        ) {
            that._last_pagination_state = current;
            that.sid('pagination_info').render();
            that.sid('pagination_controls').render();
        }
    }

    // Load data for specified page via child component
    async load_page(page) {
        let that = this;

        // Update state
        that.state.page = page;

        // Update UI with requested values (optimistic update)
        that.update_header();
        that.update_pagination();

        // Get child component instance
        const body_component = that.sid('datagrid_table_body');

        // Update child component args (state for its on_load)
        body_component.args.page = that.state.page;
        body_component.args.per_page = that.state.per_page;
        body_component.args.sort = that.state.sort;
        body_component.args.order = that.state.order;
        body_component.args.filter = that.state.filter;

        console.log('Body set to ', body_component.args);
        console.log('Reloading');

        // Reload child component (triggers on_load and re-render)
        await body_component.reload();

        // Scroll to top of datagrid if it's not currently visible
        that.scroll_to_top_if_needed();
    }

    // Register render callbacks for interactive elements
    register_render_callbacks() {
        let that = this;

        // Attach sortable header click handler - re-runs every time datagrid_table_header renders
        that.sid('datagrid_table_header').on('render', function () {
            console.log('DG render');
            // Transform th[data-sortby] elements by wrapping contents in clickable link
            $(this)
                .find('th[data-sortby]')
                .each(function () {
                    let $th = $(this);
                    let sortby = $th.attr('data-sortby');

                    // TODO: Find out why this on('render') callback is being called twice/on already-processed HTML
                    // This unwrap logic shouldn't be necessary - template should render fresh each time
                    // For now, unwrap already-wrapped content to prevent double-wrapping
                    let $existing_link = $th.find('a.DataGrid_Abstract__sortable-header');
                    let contents;
                    if ($existing_link.length > 0) {
                        // Unwrap - get the text content without the wrapper and arrows
                        contents = $existing_link.clone().find('i.bi').remove().end().html();
                    } else {
                        contents = $th.html();
                    }

                    // Build the arrow icon HTML if this column is currently sorted
                    let arrow = '';
                    if (that.state.sort === sortby) {
                        arrow =
                            that.state.order === 'desc'
                                ? '<i class="bi bi-chevron-up ms-1"></i>'
                                : '<i class="bi bi-chevron-down ms-1"></i>';
                    }

                    // Replace contents with wrapped link (fresh wrapper every time)
                    $th.html(`<a href="#" class="DataGrid_Abstract__sortable-header" data-sortby="${sortby}">${contents}${arrow}</a>`);
                });

            // Attach click handlers to the sortable links we just created
            $(this)
                .find('a.DataGrid_Abstract__sortable-header[data-sortby]')
                .on('click', function (e) {
                    e.preventDefault();
                    const sortby = $(this).attr('data-sortby');
                    that.sort_by(sortby);
                });
        });

        // Attach pagination click handler using event delegation
        // We attach to the pagination_controls element which persists, and let clicks bubble up
        // This avoids timing issues with slot content not being rendered when 'render' event fires
        that.$sid('pagination_controls').on('click', '.page-link', function (e) {
            e.preventDefault();

            const $link = $(this);
            const page = int($link.attr('data-page'));

            // Ignore disabled/ellipsis clicks
            if (!page || isNaN(page) || $link.parent().hasClass('disabled')) {
                return;
            }

            // Load the requested page
            that.load_page(page);
        });
    }

    // Register filter input handlers
    // TODO: The fact that the filter element is defined on the concrete implementation is a bit of a weird
    // pattern, but since filter hasn't been implemented at all, we can leave this alone for now.
    register_filter_handlers() {
        let that = this;

        // Find filter input by common identifiers
        let $filter = that.$sid('filter_input');
        if (!$filter || $filter.length === 0) {
            $filter = that.$.find('input[type="search"], input[type="text"].filter-input');
        }

        if ($filter && $filter.length > 0) {
            $filter.on('input keyup', function () {
                const filter_value = $(this).val();
                that.filter_changed(filter_value);
            });
        }
    }

    // Register ready callbacks for body.  when data changes, update pagnation values
    register_ready_callbacks() {
        let that = this;

        const body_component = that.sid('datagrid_table_body');

        // When child is ready, it has up to date information on the actual pagnation state, we should
        // update ourselves
        body_component.on('ready', function () {
            // Read metadata back from child's loaded data
            // Note: We don't read sort/order back because parent is source of truth for UI state
            that.state.total = body_component.data.total;
            that.state.total_pages = body_component.data.total_pages;
            that.state.page = body_component.data.page;
            that.state.per_page = body_component.data.per_page;

            // Persist state to URL hash for bookmarking/sharing
            // Only set values that differ from defaults (null removes the key)
            const hash_state = {};
            hash_state[that._cid + '_page'] = that.state.page !== that.default_state.page ? that.state.page : null;
            hash_state[that._cid + '_sort'] = that.state.sort !== that.default_state.sort ? that.state.sort : null;
            hash_state[that._cid + '_order'] = that.state.order !== that.default_state.order ? that.state.order : null;
            hash_state[that._cid + '_filter'] = that.state.filter !== that.default_state.filter ? that.state.filter : null;

            Rsx.url_hash_set(hash_state);

            // Update pagination with new totals
            that.update_pagination();
        });
    }

    filter_changed(filter) {
        let that = this;

        that.state.filter = filter;
        that.load_page(1);
    }

    // Scroll to datagrid top if the top edge is not currently visible in viewport
    scroll_to_top_if_needed() {
        let that = this;

        const $datagrid = that.$;
        const datagridTop = $datagrid.offset().top;
        const scrollTop = $(window).scrollTop();

        // If datagrid top is above the current viewport, scroll to show it
        if (datagridTop < scrollTop) {
            // If datagrid is within 300px of page top, scroll to 0
            if (datagridTop <= 300) {
                window.scrollTo({ top: 0, behavior: 'instant' });
            } else {
                // Scroll to 20px above datagrid
                window.scrollTo({ top: datagridTop - 20, behavior: 'instant' });
            }
        }
    }

    // Measure actual row height and set fixed tbody min-height
    // All happens in one animation frame so user doesn't see it
    async measure_and_set_fixed_height() {
        let that = this;

        // Wait for next animation frame to ensure DOM is ready
        await sleep(0);

        const $tbody = that.$sid('datagrid_table_body');

        // Temporarily render a single measurement row
        const $measurement_row = $('<tr>').css('visibility', 'hidden').html('<td>Measuring...</td>');
        $tbody.append($measurement_row);

        // Measure the row height
        const row_height = $measurement_row.outerHeight();

        // Remove measurement row
        $measurement_row.remove();

        // Calculate and set min-height based on per_page
        const min_height = row_height * that.state.per_page;
        $tbody.css('min-height', min_height + 'px');

        // Store for future reference
        that.state.row_height = row_height;
        that.state.tbody_min_height = min_height;
    }

    // Public method for external code to reload the grid
    async reload() {
        let that = this;
        await that.load_page(that.state.page);
    }
}
