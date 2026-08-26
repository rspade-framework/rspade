/**
 * DataGrid_Abstract Component
 *
 * Parent component managing datagrid state (pagination, sorting, filtering, selection).
 * Does NOT use on_load() - delegates data fetching to child DataGrid_Body component.
 *
 * **Architecture**:
 * - Parent (this): Manages state in this.state (page, sort, filter, custom filters, selection)
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
 * - Filtering (debounced search input + custom filter whitelist)
 * - Selection (additive / subtractive) with footer mass actions
 * - URL state synchronization
 *
 * **Usage**:
 * ```html
 * <Contacts_DataGrid $data_source="Frontend_Contacts_Controller.datagrid_fetch" />
 * ```
 *
 * ```javascript
 * class Contacts_DataGrid extends DataGrid_Abstract {
 *     static allowed_filters = ['type_id', 'is_active'];
 *     static default_filters = { is_active: 1 };
 *     static record_noun_plural = 'contacts';
 *
 *     async on_footer_action(action, selection) { ... }
 * }
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
    /**
     * Whitelist of custom filter keys this grid accepts.
     * Concrete classes override. Keys must match the param names the PHP
     * build_query() reads, because they are sent as top-level Ajax params.
     * @type {string[]}
     */
    static allowed_filters = [];

    /**
     * Custom filter values applied on boot, before the URL hash is restored
     * (so a hash value always wins over a default).
     * @type {Object}
     */
    static default_filters = {};

    /**
     * Plural noun for this grid's records, used in selection copy
     * ("Selected all 16 contacts across all pages"). Concrete classes override.
     * @type {string}
     */
    static record_noun_plural = 'records';

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
            custom_filters: {},
            total: 0,
            total_pages: 0,
            // Selection tracking. mode null = nothing selected; 'additive' = ids ARE the
            // selection; 'subtractive' = the whole filtered result set EXCEPT ids.
            selection: {
                mode: null,
                ids: {},
                frozen_filters: null,
            },
        };

        if (!that.args.data_source) {
            console.error('Datagrid ' + that.component_name() + ' requires args.data_source set to an Ajax_Endpoint');
            return;
        }

        // Seed the live state from the template args. These are the grid's defaults AND its
        // starting values - seeding only the defaults left the first request asking for
        // sort:null/order:'asc', which silently overrode every grid's PHP $default_order.
        if (that.args.per_page) that.state.per_page = int(that.args.per_page);
        if (that.args.sort) that.state.sort = that.args.sort;
        if (that.args.order) that.state.order = that.args.order;

        // Apply the concrete class's default custom filters BEFORE the hash restore.
        const defaults = that.constructor.default_filters;
        for (const key in defaults) {
            if (defaults[key] !== null && defaults[key] !== undefined) {
                that.state.custom_filters[key] = defaults[key];
            }
        }

        // Snapshot the defaults - the URL hash only carries values that differ from these
        that.default_state = clone(that.state);

        // Initialize state from URL hash if present (overrides defaults)
        const hash_page = Rsx.url_hash_get(that._cid + '_page');
        const hash_sort = Rsx.url_hash_get(that._cid + '_sort');
        const hash_order = Rsx.url_hash_get(that._cid + '_order');
        const hash_filter = Rsx.url_hash_get(that._cid + '_filter');

        if (hash_page) that.state.page = int(hash_page);
        if (hash_sort) that.state.sort = hash_sort;
        if (hash_order) that.state.order = hash_order;
        if (hash_filter) that.state.filter = hash_filter;

        // Custom filters from the hash - whitelisted keys only, as {cid}_f_{key}.
        // The '~' sentinel means "explicitly cleared": a key with a default_filters value
        // has no way to say "no filter" by absence (absence means "use the default"), so
        // clearing one persists as '~' and restores by deleting the defaulted value.
        for (const key of that.constructor.allowed_filters) {
            const hash_value = Rsx.url_hash_get(that._cid + '_f_' + key);
            if (hash_value === '~') {
                delete that.state.custom_filters[key];
            } else if (hash_value !== null && hash_value !== undefined) {
                that.state.custom_filters[key] = hash_value;
            }
        }
    }

    // Initialize datagrid after DOM ready
    async on_ready() {
        let that = this;

        that.register_render_callbacks();
        that.register_ready_callbacks();
        that.register_filter_handlers();
        that.register_selection_handlers();
        that.register_footer_action_handlers();

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

    // Apply a new free-text filter value and go back to page 1
    filter_changed(filter) {
        let that = this;

        that.state.filter = filter;
        that.load_page(1);
    }

    // Clear the free-text filter, the input widget and reload from page 1
    clear_filter() {
        let that = this;

        that.state.filter = '';

        const $filter = that.$sid('filter_input');
        if ($filter && $filter.length > 0) {
            $filter.val('');
        }

        that.load_page(1);
    }

    /**
     * Set a custom filter value (e.g. type_id, status).
     * Key must be in the static allowed_filters whitelist.
     *
     * @param {string} key - Filter key (must be in allowed_filters)
     * @param {string|number|null} value - Filter value (null/empty removes the filter)
     */
    set_custom_filter(key, value) {
        let that = this;

        if (!that.constructor.allowed_filters.includes(key)) {
            console.error('DataGrid: filter key "' + key + '" not in allowed_filters whitelist');
            return;
        }

        if (value === null || value === undefined || value === '') {
            delete that.state.custom_filters[key];
        } else {
            that.state.custom_filters[key] = value;
        }

        that.load_page(1);
    }

    /**
     * Batch-set multiple custom filters with a single reload.
     *
     * @param {Object} filters - {key: value} pairs (null/empty removes the filter)
     */
    set_custom_filters(filters) {
        let that = this;

        for (const key in filters) {
            if (!that.constructor.allowed_filters.includes(key)) {
                console.error('DataGrid: filter key "' + key + '" not in allowed_filters whitelist');
                continue;
            }

            const value = filters[key];

            if (value === null || value === undefined || value === '') {
                delete that.state.custom_filters[key];
            } else {
                that.state.custom_filters[key] = value;
            }
        }

        that.load_page(1);
    }

    /**
     * Current value of one custom filter.
     *
     * @param {string} key - Filter key
     * @returns {string|number|null} Current value, or null when not set
     */
    get_custom_filter(key) {
        return this.state.custom_filters[key] ?? null;
    }

    /**
     * Copy of every active custom filter.
     *
     * @returns {Object}
     */
    get_all_custom_filters() {
        return clone(this.state.custom_filters);
    }

    /**
     * Clear custom filters and reload. With keys given, clears only those.
     *
     * @param {string[]} [keys] - Specific keys to clear (omit for all)
     */
    clear_custom_filters(keys) {
        let that = this;

        if (keys) {
            for (const key of keys) {
                delete that.state.custom_filters[key];
            }
        } else {
            that.state.custom_filters = {};
        }

        that.load_page(1);
    }

    /**
     * Handle a footer mass-action menu item click.
     * Override in concrete datagrid classes.
     *
     * @param {string} action - The data-action value from the clicked menu item
     * @param {Object} selection - {mode: 'additive'|'subtractive'|'all', ids: number[], total: number, filter_params: Object}
     */
    on_footer_action(action, selection) {
        // Stub - override in subclass
    }

    /**
     * Number of records a footer-action selection payload covers.
     * additive -> the explicit id list; subtractive/all -> the filtered total minus exclusions.
     *
     * @param {Object} selection - payload handed to on_footer_action()
     * @returns {number}
     */
    selection_size(selection) {
        if (selection.mode === 'additive') {
            return count(selection.ids);
        }

        return Math.max(0, int(selection.total) - count(selection.ids));
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

        // A selection is scoped to the filters it was made under - if those moved, it is stale
        that._check_filters_changed();

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

        // Custom filters travel as ONE object arg, which the body merges into the Ajax payload
        // as top-level params. One named channel rather than loose args, so no framework arg
        // can ever leak into the request and no filter key can collide with a body arg.
        body_component.args.custom_filters = that._get_active_custom_filters();

        // Reload child component (triggers on_load and re-render)
        await body_component.reload();

        // Scroll to top of datagrid if it's not currently visible
        that.scroll_to_top_if_needed();
    }

    // Register render callbacks for interactive elements
    register_render_callbacks() {
        let that = this;

        // The <thead> is $redrawable, so anything bound directly to a th dies on the next
        // header render. ONE delegated handler on the stable component root instead.
        that.$.off('click.dg_sort').on('click.dg_sort', 'th[data-sortby]', function (e) {
            e.preventDefault();

            const $element = $(this);
            that.sort_by($element.attr('data-sortby'));
        });

        // Wrap header contents in a sortable link + arrow, now and on every header render
        that.transform_sortable_headers();
        that.sid('datagrid_table_header').on('render', function () {
            that.transform_sortable_headers();
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

    // Wrap each sortable th's contents in a link and paint the current sort arrow.
    // Re-run after every header render; safe to call on already-transformed markup.
    transform_sortable_headers() {
        let that = this;

        that.$sid('datagrid_table_header')
            .find('th[data-sortby]')
            .each(function () {
                const $th = $(this);
                const sortby = $th.attr('data-sortby');

                // Recover the original label when this th was already transformed
                const $existing_link = $th.find('a.DataGrid_Abstract__sortable-header');
                let contents;
                if ($existing_link.length > 0) {
                    contents = $existing_link.clone().find('i.bi').remove().end().html();
                } else {
                    contents = $th.html();
                }

                // Arrow points the way the rows run: desc = down, asc = up
                let arrow = '';
                if (that.state.sort === sortby) {
                    arrow =
                        that.state.order === 'desc'
                            ? '<i class="bi bi-chevron-down ms-1"></i>'
                            : '<i class="bi bi-chevron-up ms-1"></i>';
                    $th.addClass('is-sorted');
                } else {
                    $th.removeClass('is-sorted');
                }

                $th.html('<a href="#" class="DataGrid_Abstract__sortable-header">' + contents + arrow + '</a>');
            });
    }

    // Register the debounced search-input handler. The input itself lives in the concrete
    // grid's DG_Card_Header slot (tagged $sid="filter_input"), so its placement and
    // placeholder are the grid's to style; the behavior is owned here.
    register_filter_handlers() {
        let that = this;

        // Find filter input by common identifiers
        let $filter = that.$sid('filter_input');
        if (!$filter || $filter.length === 0) {
            $filter = that.$.find('input[type="search"], input[type="text"].filter-input');
        }

        if (!$filter || $filter.length === 0) {
            return;
        }

        // ONE event ('input' alone covers typing, paste and the search field's clear button),
        // debounced so a burst of keystrokes produces a single request.
        const debounced_filter = debounce(function (value) {
            that.filter_changed(value);
        }, 200);

        $filter.on('input', function () {
            const $element = $(this);
            debounced_filter($element.val());
        });
    }

    // Register ready callbacks for body.  when data changes, update pagination values
    register_ready_callbacks() {
        let that = this;

        const body_component = that.sid('datagrid_table_body');

        // When child is ready, it has up to date information on the actual pagination state, we should
        // update ourselves
        body_component.on('ready', function () {
            // Read metadata back from child's loaded data
            // Note: We don't read sort/order back because parent is source of truth for UI state
            that.state.total = body_component.data.total;
            that.state.total_pages = body_component.data.total_pages;
            that.state.page = body_component.data.page;
            that.state.per_page = body_component.data.per_page;

            that._persist_state_to_hash();

            // Update pagination with new totals
            that.update_pagination();

            // Selection counts depend on total, and the new page's checkboxes need syncing
            that._update_selection_ui();
        });

        // Re-check the checkboxes of already-selected rows every time new rows are painted
        body_component.on('render', function () {
            that._update_selection_ui();
        });

        // Empty-state "Clear Filter" button lives in the body; the filter state lives here
        body_component.on('datagrid:clear_filter', function () {
            that.clear_filter();
        });
    }

    // Persist state to URL hash for bookmarking/sharing.
    // Only values that differ from the defaults are written (null removes the key).
    _persist_state_to_hash() {
        let that = this;

        const hash_state = {};
        hash_state[that._cid + '_page'] = that.state.page !== that.default_state.page ? that.state.page : null;
        hash_state[that._cid + '_sort'] = that.state.sort !== that.default_state.sort ? that.state.sort : null;
        hash_state[that._cid + '_order'] = that.state.order !== that.default_state.order ? that.state.order : null;
        hash_state[that._cid + '_filter'] = that.state.filter !== that.default_state.filter ? that.state.filter : null;

        const defaults = that.constructor.default_filters;
        for (const key of that.constructor.allowed_filters) {
            const value = that.state.custom_filters[key];
            if (value === undefined) {
                // Absent value: for a key with a default this is an EXPLICIT CLEAR (the
                // default would otherwise reapply on reload) - persist the '~' sentinel.
                // For a key with no default, absence IS the default - remove the hash key.
                hash_state[that._cid + '_f_' + key] = (defaults[key] !== undefined) ? '~' : null;
            } else {
                // Loose equality on purpose: hash-restored values are strings, defaults may
                // be ints - "2" and 2 are the same filter value here.
                hash_state[that._cid + '_f_' + key] = (value == defaults[key]) ? null : value;
            }
        }

        Rsx.url_hash_set(hash_state);
    }

    // Whitelisted custom filters that currently carry a value
    _get_active_custom_filters() {
        let that = this;

        const active = {};
        for (const key of that.constructor.allowed_filters) {
            if (that.state.custom_filters[key] !== undefined) {
                active[key] = that.state.custom_filters[key];
            }
        }

        return active;
    }

    // =========================================================================
    // SELECTION (checkbox tracking with additive/subtractive modes)
    // =========================================================================

    register_selection_handlers() {
        let that = this;

        // Both the header checkbox and the row checkboxes live inside re-rendered markup
        // (<thead> is $redrawable, <tbody> repaints on every page load), so every handler
        // is delegated from the stable component root.
        that.$.off('click.dg_select_all').on('click.dg_select_all', 'thead th input[type="checkbox"]', function () {
            that._toggle_select_all();
        });

        that.$.off('click.dg_row_checkbox').on('click.dg_row_checkbox', '.row-checkbox', function (e) {
            // Don't trigger row click navigation
            e.stopPropagation();

            const $element = $(this);
            that._toggle_row_checkbox($element.val());
        });

        that.$.off('click.dg_selection_clear').on('click.dg_selection_clear', '.DataGrid_Abstract__selection-badge-clear', function (e) {
            e.preventDefault();
            that._clear_selection();
        });
    }

    // Footer mass-action dropdown items
    register_footer_action_handlers() {
        let that = this;

        that.$.off('click.dg_footer_action').on(
            'click.dg_footer_action',
            '.DataGrid_Abstract__footer-actions [data-action]',
            async function (e) {
                e.preventDefault();

                const $element = $(this);
                const action = $element.attr('data-action');

                let selection;

                if (that._get_selection_count() === 0) {
                    const confirmed = await Modal.confirm(
                        'No items selected',
                        'Perform this action on all ' + that.state.total + ' records?'
                    );

                    if (!confirmed) {
                        return;
                    }

                    selection = {
                        mode: 'all',
                        ids: [],
                        total: that.state.total,
                        filter_params: that._get_current_filter_params(),
                    };
                } else {
                    selection = that._get_selection_payload();
                }

                that.on_footer_action(action, selection);
            }
        );
    }

    // Toggle a single row checkbox. The first toggle starts additive mode.
    _toggle_row_checkbox(id) {
        let that = this;

        id = int(id);
        const selection = that.state.selection;

        if (selection.mode === null) {
            selection.mode = 'additive';
            selection.frozen_filters = that._snapshot_filters();
        }

        if (selection.mode === 'additive') {
            if (selection.ids[id]) {
                delete selection.ids[id];

                // Nothing left selected - back to no selection at all
                if (count(selection.ids) === 0) {
                    selection.mode = null;
                    selection.frozen_filters = null;
                }
            } else {
                selection.ids[id] = true;
            }
        } else {
            // Subtractive: ids are exclusions
            if (selection.ids[id]) {
                delete selection.ids[id];
            } else {
                selection.ids[id] = true;
            }
        }

        that._update_selection_ui();
    }

    /**
     * Toggle the header select-all checkbox.
     *
     * Selects the ENTIRE filtered result set - the current page and every other page - by
     * entering subtractive mode with an empty exclusion set. Clicking again clears.
     * Row checkboxes on later pages render checked automatically, because
     * _update_selection_ui() runs after every body render.
     */
    _toggle_select_all() {
        let that = this;

        if (that._get_selection_count() > 0) {
            that._clear_selection();
            return;
        }

        const selection = that.state.selection;
        selection.mode = 'subtractive';
        selection.ids = {};
        selection.frozen_filters = that._snapshot_filters();

        that._update_selection_ui();

        // Across multiple pages the whole-set scope is not visually obvious (only this page's
        // boxes check) - say it out loud.
        if (int(that.state.total_pages) > 1) {
            Flash_Alert.success(
                'Selected all ' + that.state.total + ' ' + that.constructor.record_noun_plural + ' across all pages'
            );
        }
    }

    // Clear all selection state
    _clear_selection() {
        let that = this;

        that.state.selection.mode = null;
        that.state.selection.ids = {};
        that.state.selection.frozen_filters = null;

        that._update_selection_ui();
    }

    // Is this row id part of the current selection?
    _is_selected(id) {
        let that = this;

        id = int(id);
        const selection = that.state.selection;

        if (selection.mode === 'additive') {
            return !!selection.ids[id];
        }

        if (selection.mode === 'subtractive') {
            // Selected unless explicitly excluded
            return !selection.ids[id];
        }

        return false;
    }

    // How many records the current selection covers
    _get_selection_count() {
        let that = this;

        const selection = that.state.selection;

        if (selection.mode === 'additive') {
            return count(selection.ids);
        }

        if (selection.mode === 'subtractive') {
            return Math.max(0, int(that.state.total) - count(selection.ids));
        }

        return 0;
    }

    /**
     * Selection payload for mass-action handlers.
     *
     * @returns {Object} {mode, ids: number[], total, filter_params}
     */
    _get_selection_payload() {
        let that = this;

        const selection = that.state.selection;

        return {
            mode: selection.mode,
            ids: Object.keys(selection.ids).map(Number),
            total: that.state.total,
            filter_params: that._get_current_filter_params(),
        };
    }

    // Current filter params, in the shape the server receives them
    _get_current_filter_params() {
        let that = this;

        const params = {
            filter: that.state.filter,
            sort: that.state.sort,
            order: that.state.order,
        };

        Object.assign(params, that._get_active_custom_filters());

        return params;
    }

    // Snapshot of everything a selection is scoped to, for change detection
    _snapshot_filters() {
        let that = this;

        return json_encode({
            filter: that.state.filter,
            sort: that.state.sort,
            order: that.state.order,
            custom_filters: that.state.custom_filters,
        });
    }

    // A selection made under one set of filters means nothing under another - drop it
    _check_filters_changed() {
        let that = this;

        const selection = that.state.selection;

        if (selection.mode === null) {
            return;
        }

        if (that._snapshot_filters() !== selection.frozen_filters) {
            that._clear_selection();
        }
    }

    // Sync checkboxes, row highlight and the selection badge with the selection state
    _update_selection_ui() {
        let that = this;

        const selected_count = that._get_selection_count();
        const has_selection = selected_count > 0;

        // Header checkbox: checked when anything is selected, indeterminate for a partial
        // additive selection
        const $select_all = that.$sid('select_all');
        if ($select_all && $select_all.length > 0) {
            $select_all.prop('checked', has_selection);
            $select_all.prop(
                'indeterminate',
                has_selection && that.state.selection.mode === 'additive' && selected_count < int(that.state.total)
            );
        }

        // Row checkboxes on the page currently painted
        that.$.find('.row-checkbox').each(function () {
            const $checkbox = $(this);
            const is_selected = that._is_selected($checkbox.val());

            $checkbox.prop('checked', is_selected);
            $checkbox.closest('tr').toggleClass('DataGrid_Abstract__row--selected', is_selected);
        });

        // Selection badge
        const $badge = that.$sid('selection_badge');
        if ($badge && $badge.length > 0) {
            that.$sid('selection_badge_count').text(selected_count);
            $badge.toggle(has_selection);
        }
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
