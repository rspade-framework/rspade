# Standardized CRUD Pattern - Clients Module

## Directory Structure

```
/rsx/app/frontend/clients/
├── frontend_clients_controller.php    # Single controller for all CRUD operations
├── frontend_clients_bundle.php        # Asset bundling
├── index/                              # List view
│   ├── frontend_clients.blade.php     # Main listing page
│   ├── frontend_clients.js            # List-specific JavaScript
│   ├── frontend_clients.scss          # List-specific styles
│   ├── clients_datagrid.jqhtml        # DataGrid component
│   └── clients_datagrid.js            # DataGrid JavaScript class
├── view/                               # Detail view
│   ├── frontend_clients_view.blade.php
│   ├── frontend_clients_view.js
│   └── frontend_clients_view.scss
└── edit/                               # Add/Edit form (shared)
    ├── frontend_clients_form.blade.php  # Single form for both add/edit
    ├── frontend_clients_form.js
    └── frontend_clients_form.scss
```

## Controller Pattern

**Single Controller Approach**: All CRUD operations in `Frontend_Clients_Controller`

```php
class Frontend_Clients_Controller extends Rsx_Controller_Abstract
{
    #[Auth('Permission::authenticated()')]
    #[Route('/clients')]
    public static function index(Request $request, array $params = []) {
        // List view
    }

    #[Auth('Permission::authenticated()')]
    #[Route('/clients/view/:id')]
    public static function view(Request $request, array $params = []) {
        // Detail view - $params['id'] contains client ID
    }

    #[Auth('Permission::authenticated()')]
    #[Route('/clients/add')]
    public static function add(Request $request, array $params = []) {
        // Show add form (uses edit/frontend_clients_form.blade.php)
    }

    #[Auth('Permission::authenticated()')]
    #[Route('/clients/edit/:id')]
    public static function edit(Request $request, array $params = []) {
        // Show edit form with existing data
    }

    #[Auth('Permission::authenticated()')]
    #[Route('/clients/save', method: 'POST')]
    public static function save(Request $request, array $params = []) {
        // Handle both add and edit saves
        // Check for $params['id'] to determine update vs insert
    }

    #[Auth('Permission::authenticated()')]
    #[Route('/clients/delete/:id', method: 'POST')]
    public static function delete(Request $request, array $params = []) {
        // Soft delete the client
    }

    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = []) {
        // DataGrid Ajax endpoint for pagination/sorting/filtering
        // Returns: {records: [...], page: 1, total: 100, ...}
    }
}
```

## Route Patterns

- **Index**: `/clients` - List all clients with DataGrid
- **View**: `/clients/view/:id` - View single client details
- **Add**: `/clients/add` - Show blank form for new client
- **Edit**: `/clients/edit/:id` - Show form with existing client data
- **Save**: `/clients/save` (POST) - Handle form submission for both add/edit
- **Delete**: `/clients/delete/:id` (POST) - Soft delete a client

## Shared Form Pattern

The `edit/frontend_clients_form.blade.php` serves both add and edit operations:

```blade
@section('content')
<Page>
  <Page_Header>
    <Page_Title>{{ $client ? 'Edit Client' : 'New Client' }}</Page_Title>
  </Page_Header>

  <form method="POST" action="{{ Rsx::Route('Frontend_Clients_Controller', 'save') }}">
    @csrf
    @if($client)
      <input type="hidden" name="id" value="{{ $client->id }}">
    @endif

    <!-- Form fields here -->
    <input name="name" value="{{ $client->name ?? '' }}" required>

    <button type="submit">{{ $client ? 'Update' : 'Create' }} Client</button>
  </form>
</Page>
@endsection
```

## DataGrid Integration

The list screen is `list/clients_datagrid.{php,js,jqhtml}` fed by
`Frontend_Clients_Controller::datagrid_fetch`, with `bulk_delete` and `export_csv` serving the
footer mass actions.

What this module's grid declares:

- **PHP** (`Clients_DataGrid`) - `build_query()` with the free-text search across name/address/
  city/state/phone/website plus the `status_id` and `priority` quick filters; a
  `$sortable_columns` whitelist; `$secondary_sort = 'id'` so paging stays stable under the
  low-cardinality `priority` sort. No join, so column names are unqualified.
- **jqhtml** - `extends="DataGrid_Abstract"` with `$data_source`, `$sort="id"`, `$order="desc"`;
  sortable columns as literal `data-sortby` attributes in `Slot:DG_Table_Header`; the search and
  the two `<select>` quick filters in `Slot:DG_Card_Header`; `Slot:footer_actions` items carrying
  `data-action="export"` / `"delete"`, the export one gated on `PERM_DATA_EXPORT` to mirror the
  endpoint.
- **JS** (`Clients_DataGrid`) - `allowed_filters`, `record_noun_plural`, the quick-filter widget
  binding, and `on_footer_action()` dispatching to the two endpoints. `whole_set_selection()` is
  public so the page-header Export button in `Clients_Index_Action.js` can export the whole
  filtered set.

**The contracts live in `rsx/theme/components/datagrid/CLAUDE.md`** - sorting, custom filters and
their URL-hash persistence, the selection payload and its server-side resolution, and the gotchas.
Read that before changing any of the three files here.

## URL Generation

Always use controller-based routing:

```php
// PHP
Rsx::Route('Frontend_Clients_Controller', 'view', 1)
Rsx::Route('Frontend_Clients_Controller', 'add')
Rsx::Route('Frontend_Clients_Controller', 'edit', $id)

// JavaScript
Rsx.Route('Frontend_Clients_Controller', 'view', row.id)
Rsx.Route('Frontend_Clients_Controller', 'add')
```

## Key Principles

1. **Single Controller**: All CRUD operations in one controller class
2. **Feature Directories**: Organize views by feature (index/, view/, edit/)
3. **Shared Forms**: One form template for both add and edit
4. **DataGrid Abstract**: Extend base class for consistent table behavior
5. **Type-Safe URLs**: Always use `Rsx::Route()` with controller class names
6. **No Separate Add Controller**: Eliminated `Frontend_Clients_Add_Controller`
7. **Consistent Naming**: All files follow `frontend_clients_*.ext` pattern

## Migration Pattern

```php
Schema::create('clients', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('company')->nullable();
    $table->string('email')->nullable();
    $table->string('phone')->nullable();
    $table->text('address')->nullable();
    $table->text('notes')->nullable();
    $table->enum('status', ['active', 'inactive'])->default('active');
    $table->timestamps();
    $table->softDeletes();
});
```

## Model Pattern

```php
class Client_Model extends Rsx_Model_Abstract
{
    protected $table = 'clients';
    protected $fillable = ['name', 'company', 'email', 'phone', 'address', 'notes', 'status'];

    public function projects() {
        return $this->hasMany(Project_Model::class, 'client_id');
    }

    public function contacts() {
        return $this->hasMany(Contact_Model::class, 'client_id');
    }
}
```

This pattern provides a clean, consistent approach to CRUD operations that can be replicated across all modules (contacts, projects, tasks, etc.).