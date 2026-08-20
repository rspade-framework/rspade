---
name: crud-patterns
description: Standard CRUD implementation patterns in RSX including directory structure, DataGrid lists, view pages, dual-route edit actions, and three-state loading. Use when building list/view/edit pages, implementing DataGrid, creating add/edit forms, or following RSX CRUD conventions.
---

# RSX CRUD Patterns

## Directory Structure

Each CRUD feature follows this organization:

```
rsx/app/frontend/{feature}/
├── {feature}_controller.php        # Ajax endpoints
├── list/
│   ├── {Feature}_Index_Action.js       # List page
│   ├── {Feature}_Index_Action.jqhtml
│   ├── {feature}_datagrid.php          # DataGrid backend
│   └── {feature}_datagrid.jqhtml       # DataGrid template
├── view/
│   ├── {Feature}_View_Action.js        # Detail view
│   └── {Feature}_View_Action.jqhtml
└── edit/
    ├── {Feature}_Edit_Action.js        # Add/Edit (dual-route)
    └── {Feature}_Edit_Action.jqhtml

rsx/models/{feature}_model.php          # With fetch() method
```

---

## The Three Subdirectories

| Directory | Purpose | Route Pattern |
|-----------|---------|---------------|
| `list/` | Index with DataGrid | `/contacts` |
| `view/` | Single record detail | `/contacts/:id` |
| `edit/` | Add AND edit form | `/contacts/add`, `/contacts/:id/edit` |

---

## Feature Controller

The controller provides Ajax endpoints for all operations:

**`#[Auth]` is MANDATORY on every endpoint** - surfaces are closed by default and the manifest build FAILS without a gate. A class-level `#[Auth]` covers every endpoint in the class; a method-level one is ADDITIVE (gates only narrow). **`pre_dispatch()` performs NO authorization anywhere** - it exists for other middleware concerns, never for access control.

```php
#[Auth('is_logged_in')]                          // class-level: covers every endpoint below
class Frontend_Contacts_Controller extends Rsx_Controller_Abstract
{
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Contacts_DataGrid::fetch($params);
    }

    #[Ajax_Endpoint]
    #[Auth('can_manage_contacts')]               // additive - narrows this endpoint further
    public static function save(Request $request, array $params = [])
    {
        // Validation - response_form_error() carries per-field messages
        if (empty($params['name'])) {
            return response_form_error('Validation failed', ['name' => 'Required']);
        }

        // Create or update. Site scope is applied automatically on a site-scoped
        // model - never hand-write ->where('site_id', ...).
        $contact = $params['id'] ? Contact_Model::find($params['id']) : new Contact_Model();
        $contact->name  = $params['name'];       // explicit assignment, never mass assignment
        $contact->email = $params['email'];
        $contact->save();

        return ['redirect' => Rsx::Route('Contacts_View_Action', $contact->id)];
    }

    #[Ajax_Endpoint]
    #[Auth('can_manage_contacts')]
    public static function delete(Request $request, array $params = [])
    {
        $contact = Contact_Model::find($params['id']);
        $contact->delete();
        return ['redirect' => Rsx::Route('Contacts_Index_Action')];
    }
}
```

The gate answers "may this USER use this endpoint at all". Rules that depend on WHICH record (ownership, record state) stay in the body.

---

## List Page (Index Action)

```javascript
@route('/contacts')
@layout('Frontend_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')   // MANDATORY on every @route action; the build fails without it
class Contacts_Index_Action extends Spa_Action {
    async on_load() {
        // DataGrid fetches its own data
    }
}
```

```jqhtml
<Define:Contacts_Index_Action tag="div">
    <div class="d-flex justify-content-between mb-3">
        <h1>Contacts</h1>
        <a href="<%= Rsx.Route('Contacts_Edit_Action') %>" class="btn btn-primary">
            Add Contact
        </a>
    </div>

    <Contacts_DataGrid />
</Define:Contacts_Index_Action>
```

---

## DataGrid Backend

```php
class Contacts_DataGrid extends DataGrid_Abstract
{
    protected static function query(): Builder
    {
        // Site scope is automatic on a site-scoped model - never add ->where('site_id', ...)
        return Contact_Model::query();
    }

    protected static function columns(): array
    {
        return [
            'name' => ['label' => 'Name', 'sortable' => true],
            'email' => ['label' => 'Email', 'sortable' => true],
            'created_at' => ['label' => 'Created', 'sortable' => true],
        ];
    }

    protected static function default_sort(): array
    {
        return ['name' => 'asc'];
    }
}
```

---

## View Page (Three-State Pattern)

View pages use the three-state loading pattern: loading → error → content.

```javascript
@route('/contacts/:id')
@layout('Frontend_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Contacts_View_Action extends Spa_Action {
    on_create() {
        this.data.contact = null;
        this.data.error = null;
    }

    async on_load() {
        try {
            this.data.contact = await Contact_Model.fetch(this.args.id);
        } catch (e) {
            this.data.error = e;
        }
    }
}
```

```jqhtml
<Define:Contacts_View_Action tag="div">
    <% if (!this.data.contact && !this.data.error) { %>
        <Loading_Spinner />
    <% } else if (this.data.error) { %>
        <Universal_Error_Page_Component $error="<%= this.data.error %>" />
    <% } else { %>
        <h1><%= this.data.contact.name %></h1>
        <p>Email: <%= this.data.contact.email %></p>

        <a href="<%= Rsx.Route('Contacts_Edit_Action', this.data.contact.id) %>"
           class="btn btn-primary">Edit</a>
    <% } %>
</Define:Contacts_View_Action>
```

---

## Edit Page (Dual-Route Action)

A single action handles both add and edit modes:

```javascript
@route('/contacts/add')
@route('/contacts/:id/edit')
@layout('Frontend_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('can_manage_contacts')
class Contacts_Edit_Action extends Spa_Action {
    on_create() {
        this.data.form_data = { name: '', email: '' };
        this.data.is_edit = !!this.args.id;
        this.data.error = null;
    }

    async on_load() {
        if (!this.data.is_edit) return;

        try {
            const contact = await Contact_Model.fetch(this.args.id);
            this.data.form_data = {
                id: contact.id,
                name: contact.name,
                email: contact.email
            };
        } catch (e) {
            this.data.error = e;
        }
    }
}
```

```jqhtml
<Define:Contacts_Edit_Action tag="div">
    <% if (this.data.is_edit && !this.data.form_data.id && !this.data.error) { %>
        <Loading_Spinner />
    <% } else if (this.data.error) { %>
        <Universal_Error_Page_Component $error="<%= this.data.error %>" />
    <% } else { %>
        <h1><%= this.data.is_edit ? 'Edit Contact' : 'Add Contact' %></h1>

        <Rsx_Form $controller="Frontend_Contacts_Controller" $method="save"
                  $data="<%= JSON.stringify(this.data.form_data) %>">
            <Hidden_Input $name="id" />

            <Form_Field $label="Name" $required=true>
                <Text_Input $name="name" />
            </Form_Field>

            <Form_Field $label="Email">
                <Text_Input $name="email" $type="email" />
            </Form_Field>

            <button type="submit" class="btn btn-primary">
                <%= this.data.is_edit ? 'Save Changes' : 'Create Contact' %>
            </button>
        </Rsx_Form>
    <% } %>
</Define:Contacts_Edit_Action>
```

---

## Model with fetch()

```php
class Contact_Model extends Rsx_Site_Model_Abstract
{
    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]        // MANDATORY - the build fails without a gate, and the
    public static function fetch($id)   // gate is evaluated BEFORE any model code runs
    {
        $contact = static::find($id);   // site scope is already applied
        if (!$contact) { return false; }

        // Record-level rules go HERE - gates take no arguments, so they can never
        // express "only your own row". Return false, never null.
        return $contact->toArray();
    }
}
```

A denial and a missing row return the same generic "not found" (anti-enumeration). Full contract: the `model-fetch` skill.

---

## Key Principles

1. **SPA controllers = Ajax endpoints only** - No page rendering
2. **Single action handles add/edit** - Dual `@route` decorators
3. **Models implement `fetch()`** - With `#[Ajax_Endpoint_Model_Fetch]`
4. **DataGrids extend `DataGrid_Abstract`** - Query + columns + sorting
5. **Three-state pattern** - Loading -> Error -> Content. Skip it for DataGrid pages, static pages and redirect-only actions.
6. **form_data must be serializable** - Plain objects, not models
7. **Every surface is gated** - `#[Auth]` on every controller endpoint and model `fetch()`, `@auth` on every `@route` action. Closed by default; the manifest build fails without them, and `pre_dispatch()` never authorizes.

## More Information

Details: `php artisan rsx:man crud`. Related skills: `spa` (routing and layouts), `forms` (Rsx_Form and inputs), `model-fetch` (the gated fetch contract), `jqhtml` (component lifecycle).
