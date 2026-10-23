---
name: crud-patterns
description: "The CRUD convention this application is built to - the list/ view/ edit/ directory split under rsx/app/frontend/<feature>/, a DataGrid_Abstract backend with query()/columns()/default_sort(), the three-state loading pattern on a view page, the dual-@route add/edit action, @spinner_until_ready('uncached') on a view page and ('always') on an edit page, and the gated fetch() on the model. Use when building a list, view or add/edit page, adding a DataGrid, laying out a new feature directory, or copying the canonical example in rsx/app/frontend/clients/."
---

# CRUD patterns in this application

> **Living skill.** This skill ships with the template application and is yours. It describes
> the CURRENT state of `rsx/app/frontend/` and `rsx/theme/components/datagrid/`; the directory
> files `rsx/app/frontend/CLAUDE.md` and `rsx/theme/components/datagrid/CLAUDE.md` are its
> companions. When this feature changes, update this skill and those files in the same pass.

The shape below is this application's convention, not a framework rule - the routing,
gating and ORM pieces it uses are framework contracts (`rspade:spa`, `rspade:auth-gates`,
`rspade:model-fetch`). `DataGrid_Abstract` itself is app code, in
`rsx/theme/components/datagrid/`, and is yours to change. The canonical worked example is
`rsx/app/frontend/clients/`.

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

**This application's data checks are part of the convention**: the class carries `is_logged_in` + `can_view_data` (reads), every write adds `can_edit_data`, and the model's `fetch()` adds `can_view_data`. `rsx/tests/Endpoint_Data_Gates_Test.php` fails the suite when a write-shaped endpoint under `rsx/app/` lacks `can_edit_data` (or an administrative check) or a read carries nothing beyond `is_logged_in`. Every id a save stores (a `client_id`, a pivot id) is looked up through its site-scoped model first, so another tenant's id is a field error.

```php
#[Auth('is_logged_in', 'can_view_data')]         // class-level: covers every endpoint below
class Frontend_Contacts_Controller extends Rsx_Controller_Abstract
{
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Contacts_DataGrid::fetch($params);
    }

    #[Ajax_Endpoint]
    #[Auth('can_edit_data')]                     // additive - narrows this endpoint further
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
    #[Auth('can_edit_data')]
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
@auth('is_logged_in', 'can_view_data')   // MANDATORY on every @route action; the build fails without it
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

View pages use the three-state loading pattern: loading → error → content. Every entity view
page also declares `@spinner_until_ready('uncached')`: a first visit is held behind the spinner
until the whole tree (self-loading regions included) is ready, and a revisit paints from cache
at once (skill `rspade:spa`).

```javascript
@route('/contacts/:id')
@layout('Frontend_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data')
@spinner_until_ready('uncached')
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

A single action handles both add and edit modes, and declares
`@spinner_until_ready('always')`: on every visit it stays hidden behind the spinner until it is
ready, so the form is never seen - or typed into - before its record is in. There is no loading
overlay, no "settled" flag and no re-seeding of the form to write.

```javascript
@route('/contacts/add')
@route('/contacts/:id/edit')
@layout('Frontend_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in', 'can_view_data', 'can_edit_data')
@spinner_until_ready('always')
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

**Unsaved changes.** The contacts, tasks and projects edit actions also turn the
navigation guard on at the user's first change and off again, so the browser asks before
an edited form is left: the form's `'input'` calls `Rsx.set_navigation_guard()`,
`'submitted'` and the Cancel link (`@click=this.discard_changes`) call
`Rsx.clear_navigation_guard()`, and so does `on_stop()`. The commented copy is
`rsx/app/frontend/contacts/edit/Contacts_Edit_Action.js`; skill `rspade:navigation-guard`.

```jqhtml
<Define:Contacts_Edit_Action tag="div">
    <% if (this.data.error) { %>
        <%-- A LOAD FAILURE is a page, not a placeholder - there is no form to wait for. --%>
        <Universal_Error_Page_Component $error="<%= this.data.error %>" />
    <% } else { %>
        <h1><%= this.data.is_edit ? 'Edit Contact' : 'Add Contact' %></h1>

        <Rsx_Form $controller="Frontend_Contacts_Controller" $method="save"
                  $data="<%= JSON.stringify(this.data.form_data) %>">
            <Hidden_Input $name="id" />

            <Form_Errors />

            <Form_Field $label="Name" $required=true>
                <Text_Input $name="name" $max_length=Contact_Model.field_length('name') />
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
    #[Auth('can_view_data')]       // MANDATORY - the build fails without a gate, and the
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

A denial and a missing row return the same generic "not found" (anti-enumeration). Full contract: skill `rspade:model-fetch`.

The model is also where a column's KIND is declared: `$enums` for an integer enum, `$text_types` for a TEXT column holding rich text (`'description' => Rich_Text::class`, which is why the edit page writes `<{Project_Model.editor_component_for('description')} $name="description" />` and names no widget). Skills `rspade:model-enums`, `rspade:text-types`.

---

## Key Principles

1. **SPA controllers = Ajax endpoints only** - No page rendering
2. **Single action handles add/edit** - Dual `@route` decorators
3. **Models implement `fetch()`** - With `#[Ajax_Endpoint_Model_Fetch]`
4. **DataGrids extend `DataGrid_Abstract`** - Query + columns + sorting
5. **Three-state pattern** - Loading -> Error -> Content, for VIEW pages, with `@spinner_until_ready('uncached')`. An EDIT FORM instead renders the real form, keeping only the error branch, and declares `@spinner_until_ready('always')`. Skip the pattern entirely for DataGrid pages, static pages and redirect-only actions.
6. **form_data must be serializable** - Plain objects, not models
7. **Every surface is gated** - `#[Auth]` on every controller endpoint and model `fetch()`, `@auth` on every `@route` action. Closed by default; the manifest build fails without them, and `pre_dispatch()` never authorizes.

## More Information

Details: `php artisan rsx:man crud`. Related skills: `rspade:spa` (routing and layouts),
`rspade:form-engine` (the `Rsx_Form` engine) and the app skill `form-components` (`Form_Field`
and the input roster), `rspade:model-fetch` (the gated fetch contract), `rspade:jqhtml`
(component lifecycle), and the app skill `semantic-components` (the widget vocabulary a list
or view page is composed from).
