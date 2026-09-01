# rsx/theme/components/datagrid — the paginated table engine

## WHAT IS HERE

- `datagrid_abstract.php` — the server half: query building, sort validation, pagination
  math and the selection helpers. A grid extends it and implements `build_query()`.
- `datagrid_abstract.js` — ALL grid state: page, sort, order, filter, custom filters,
  selection. It never calls Ajax itself.
- `datagrid_abstract.jqhtml` / `.scss` — the card, `<thead>` and footer shell, plus the
  slots a concrete grid fills.
- `datagrid_body.{jqhtml,js}` — the `<tbody>`, and the ONLY component that talks to the
  server.
- `pagination_controls.jqhtml`, `pagination_info.jqhtml` — the footer widgets.

## HOW TO CUSTOMIZE

- **A grid of your own is three co-located files** (`<thing>_datagrid.{php,js,jqhtml}`)
  extending these abstracts — not an edit to them. Worked example:
  `rsx/app/frontend/clients/list/clients_datagrid.{php,js,jqhtml}`.
- **Restyle every grid at once** in `datagrid_abstract.scss` (single `.DataGrid_Abstract`
  wrap, BEM children with the exact PascalCase prefix). A page-scoped override of grid
  chrome is the anti-pattern this shared abstract exists to prevent.
- **Never move the Ajax call out of `DataGrid_Body`.** The parent owning state and the
  child owning the single request is what keeps one grid from firing two fetches per page.
- The `$sid` names, the `Slot:DG_*` slot names and the `data-sortby` / `data-action` /
  `data-href` attributes are the contract between these files and every concrete grid —
  renaming one silently breaks every grid in the app.
- The whole directory is app code and may be replaced wholesale; the framework has no
  DataGrid.

## HOW IT IS USED

Paginated, sortable, filterable tables with cross-page selection and mass actions.

**Worked example (read this first):** `rsx/app/frontend/clients/list/clients_datagrid.{php,js,jqhtml}`
plus the `datagrid_fetch` / `bulk_delete` / `export_csv` endpoints in
`rsx/app/frontend/clients/clients_controller.php`.

## Architecture

```
DataGrid_Abstract (php)     - query building, sort validation, pagination math, selection helpers
DataGrid_Abstract (js)      - ALL state: page, sort, order, filter, custom_filters, selection
DataGrid_Abstract (jqhtml)  - card + thead + footer shell, slots
    └─ DataGrid_Body        - the <tbody>: the ONLY component that talks to the server
```

The parent holds state and **never** calls Ajax. `load_page()` writes the state onto the child's
`args` and calls `child.reload()`; the child's `on_load()` issues the one request. The child
reports back through its `ready` event, at which point the parent reads `total`, `total_pages`,
`page`, `per_page` off `body_component.data`, persists the URL hash and repaints the footer.

`this.data` is unused on the parent (it has no `on_load()`); the template reads `this.state`.

Request params: `{page, per_page, sort, order, filter, ...custom_filters}` - custom filters are
merged in as TOP-LEVEL keys, which is how `build_query($params)` reads them.

Response shape from `DataGrid_Abstract::fetch()`:

```php
['records' => [...], 'page' => 1, 'per_page' => 15, 'total' => 0, 'total_pages' => 0,
 'sort' => 'id', 'order' => 'desc']
```

## Declaring a grid

Three co-located files named for the class, e.g. `clients_datagrid.{php,js,jqhtml}`.

**PHP** - extends `Rsx\Theme\Components\Datagrid\DataGrid_Abstract`, implements
`build_query(array $params): Builder`. Optional overrides: `$default_per_page` (15),
`$max_per_page` (100), `$default_sort` ('id'), `$default_order` ('desc'), `$sortable_columns`,
`$secondary_sort` / `$secondary_order`, `map_sort_column()`, `transform_records()`.

**Controller** - one endpoint forwarding to `fetch()`:

```php
#[Ajax_Endpoint]
public static function datagrid_fetch(Request $request, array $params = [])
{
    return Clients_DataGrid::fetch($params);
}
```

**jqhtml** - `extends="DataGrid_Abstract"`, filling the slots:

```jqhtml
<Define:Clients_DataGrid
    extends="DataGrid_Abstract"
    $data_source=Frontend_Clients_Controller.datagrid_fetch
    $sort="id" $order="desc" $per_page=15
    class="card DataGrid">
```

Slots: `DG_Card_Header` (title, search, quick filters), `DG_Table_Header` (`<tr>` of `<th>`),
`row` (one `<tr>`, receives `row`), `footer_actions` (`<li>` items), `DG_Empty_State`.

**JS** - extends `DataGrid_Abstract` for filters, selection copy and mass actions. A grid with
none of those needs no `.js` file at all.

## Sorting

Three things must line up or the column silently will not sort:

1. `<th data-sortby="name">` in `Slot:DG_Table_Header` - a **literal** attribute.
2. `'name'` present in the PHP `$sortable_columns` whitelist. An empty whitelist disables
   validation entirely; a sort key not in a non-empty whitelist falls back to `$default_sort`.
3. `map_sort_column()` translating the key to a real SQL column when the query joins or aliases.

The JS wraps each sortable `<th>`'s contents in a link and paints a chevron
(`transform_sortable_headers()`), toggling asc/desc when the same column is clicked twice.

`$secondary_sort` is a tie-break applied after the primary sort (skipped when it IS the primary
sort). Name one whenever the primary sort column is low-cardinality or non-unique, or rows
shuffle between pages.

The template args `$sort` and `$order` seed the LIVE state, not just a default - **declare both**.
Declaring only `$sort` leaves the grid asking for `order:'asc'` on its first request and silently
overriding the PHP `$default_order`.

## Quick filters

```javascript
class Clients_DataGrid extends DataGrid_Abstract {
    static allowed_filters = ['status_id', 'priority'];   // whitelist; keys are the PHP param names
    static default_filters = { status: Project_Model.STATUS_ACTIVE };  // applied before the hash restore
    static record_noun_plural = 'clients';                // used in selection copy
}
```

API: `set_custom_filter(key, value)` (null/'' removes), `set_custom_filters({...})` (one reload),
`get_custom_filter(key)`, `get_all_custom_filters()`, `clear_custom_filters([keys])`. Every setter
resets to page 1. A key outside `allowed_filters` is refused with a console error.

Server side reads them straight off `$params` in `build_query()`.

**Widget wiring** - widgets live in `Slot:DG_Card_Header` with a `$sid`, are bound in the grid's
own `on_ready()` (call `super.on_ready()` first) and are set FROM state, never the reverse:

```javascript
on_ready() {
    super.on_ready();
    this._bind_quick_filter('status_filter', 'status_id');
}

_bind_quick_filter(sid, key) {
    let that = this;
    const $select = that.$sid(sid);
    $select.val(str(that.get_custom_filter(key) ?? ''));
    $select.on('change', function () {
        const $element = $(this);
        that.set_custom_filter(key, $element.val() || null);
    });
}
```

`default_filters` and the hash restore have both already run in `on_create()`, so the state is
authoritative by `on_ready()`. Never express a default as a `selected` attribute in markup.

The free-text search is a `<Search_Input $sid="filter_input">` in the same slot; the abstract finds
it by that `$sid` and debounces it at 200ms.

**Hash persistence** - keys are `{cid}_page`, `_sort`, `_order`, `_filter` and `_f_{key}` per custom
filter, and only values DIFFERING from the defaults are written. A filter carrying a
`default_filters` value cannot say "cleared" by absence (absence means "reapply the default"), so a
cleared defaulted filter persists as the sentinel **`~`**, which the `on_create()` restore reads as
"delete this key". Comparison against defaults is deliberately loose (`==`): hash values come back
as strings, defaults are often ints.

## Selection and mass actions

Two modes, both scoped to the ENTIRE filtered result set rather than the visible page:

- `additive` - `ids` ARE the selection (starts on the first row checkbox click).
- `subtractive` - everything the filters match EXCEPT `ids` (the header select-all checkbox).
- `all` - synthesized when a footer action is fired with nothing ticked and the user confirms.

Markup requirements: `<input class="form-check-input" type="checkbox" $sid="select_all">` in a header
`<th>`, and `<input class="form-check-input row-checkbox" type="checkbox" value="<%= row.id %>">` in
the row slot. All handlers are delegated from the component root, so re-rendered thead/tbody markup
keeps working. Selection state is invalidated whenever filter/sort state moves
(`_check_filters_changed()` against a frozen snapshot).

`Slot:footer_actions` items carry `data-action`; clicking one calls
`on_footer_action(action, selection)`:

```javascript
selection = { mode: 'additive'|'subtractive'|'all', ids: number[], total: 0, filter_params: {...} }
```

`filter_params` is `{filter, sort, order, ...custom_filters}` - the exact shape `build_query()`
receives. `selection_size(selection)` gives the record count for confirmation copy.

**Server resolution** is always the same three steps - rebuild the query from `filter_params`,
constrain it by the id set, iterate it:

```php
#[Ajax_Endpoint]
public static function bulk_delete(Request $request, array $params = [])
{
    $query = Clients_DataGrid::build_query_public($params['filter_params'] ?? []);
    $query = Clients_DataGrid::apply_selection($query, 'clients.id', $params);

    if ($query instanceof Error_Response) {
        return $query;   // malformed payload; returned verbatim
    }

    $deleted = 0;

    foreach (Clients_DataGrid::iterate_selection($query, 'clients.id') as $client) {
        Action_Log::record(Action_Log_Model::TYPE_CLIENT_DELETED, $client);
        $client->delete();
        $deleted++;
    }

    return ['deleted' => $deleted];
}
```

Never trust the client id list to be complete - `filter_params` is what makes "delete everything
matching what I'm looking at" mean the same thing on both sides. Every record goes through the
model layer one at a time; a raw bulk DELETE would skip the soft delete, the audit stamp, the
realtime frame and the action log.

**The id column is ALWAYS table-qualified** (`'clients.id'`, `'projects.id'`) in both
`apply_selection()` and `iterate_selection()`. A joined grid pulls in another table's `id`, and an
unqualified name is an ambiguous-column error at best, the wrong table's rows at worst.

**`iterate_selection()`, not `->result_set()`.** `Rsx_Result_Set` calls `lazyById()` with no column,
and Laravel's default key name is the UNQUALIFIED `'id'` - which MySQL rejects the moment the query
joins a table carrying an `id`. `iterate_selection()` passes the qualified column and aliases the
cursor back to `id`. It walks the whole set, keyset page at a time; there is no truncating LIMIT.

An additive selection with no ids selects NOTHING (`whereIn([])`) - deliberately not shortcut into
"everything".

**CSV export** - `DataGrid_Abstract::build_csv($headers, $rows)` returns an RFC4180 string, written
through `League\Csv\Writer` over a `php://temp` stream - league/csv is the framework's CSV library
and no code here escapes a field by hand (`rsx:man csv_exports`). An Ajax response cannot BE a
download, so the endpoint returns
`['csv' => ..., 'filename' => ..., 'count' => ...]` and the client calls
`trigger_file_download(content, filename[, mime_type])` from `rsx/lib/file_download.js`. Menu items for gated actions
mirror the endpoint's `#[Auth]` in the template (`Permission.has_permission(...)`) so the menu never
offers what the server refuses. An export does NOT clear the selection; a delete does.

**Excel export** - same shape, different container: the endpoint builds a workbook with
PhpSpreadsheet and returns `['xlsx_base64' => ..., 'filename' => ..., 'count' => ...]`, because JSON
carries no bytes. The client rebuilds them with `base64_to_bytes()` (same file) and passes the xlsx
MIME type to `trigger_file_download()`. Worked example: `Frontend_Clients_Controller::export_xlsx`
and `Clients_DataGrid.export_selection_xlsx`.

A page-header button can drive the same machinery with a whole-set payload - see
`whole_set_selection()` in `clients_datagrid.js` and its caller in `Clients_Index_Action.js`.

## Empty state

`Slot:DG_Empty_State` (see `rsx/app/frontend/settings/api_keys/api_keys_datagrid.jqhtml`) replaces
the generic "No results found" block - but ONLY when the grid is genuinely empty. A search that
matched nothing still shows the "No results found for X" message with its Clear Filter button,
because a first-run empty state is a lie about a filtered result. Slot presence travels to
`DataGrid_Body` as the `$has_empty_state` boolean, since a child cannot tell an absent slot from
an empty one.

## Gotchas

- **jqhtml `$`-attributes never emit `data-*`.** `$sortby` sets a component ARG. Sortable headers
  must be written as the literal attribute `data-sortby="col"`; likewise `data-action` on footer
  items and `data-href` on rows.
- **The `<thead>` is `$redrawable`** and the `<tbody>` repaints on every page load. Anything bound
  directly to a `th`, a row checkbox or a footer item dies on the next render - bind delegated from
  the component root (`that.$.on('click.ns', 'selector', ...)`), which is what the abstract does.
- **Declare BOTH `$sort` and `$order`** in the `<Define:>` - see Sorting above.
- **Once a grid joins, qualify everything** - the select, the search `where`s, the quick-filter
  `where`s, `map_sort_column()`, and the id column handed to `apply_selection()` /
  `iterate_selection()`. `projects_datagrid.php` is the worked example.
- **Never write a class constant as a PHP parameter default** in a scanned class
  (`function f($n = Rsx_Result_Set::DEFAULT_CHUNK_SIZE)`). The manifest scanner calls
  `ReflectionParameter::getDefaultValue()` during the scan, which resolves the constant before the
  autoloader is ready and kills the build - the same landmine as a constant in an attribute
  argument. Use the constant inside the body instead.
- `per_page` is clamped into `[1, $max_per_page]`; a request for 0 is not a request for the maximum.
- The parent reads `total`/`page`/`per_page` back from the child but NOT `sort`/`order` - the parent
  is the source of truth for the UI's sort state.
