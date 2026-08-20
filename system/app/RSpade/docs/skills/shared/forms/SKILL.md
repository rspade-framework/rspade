---
name: forms
description: Building RSX forms with Rsx_Form, Form_Field, input components, data binding, validation, and the vals() pattern. Use when creating forms, handling form submissions, implementing form validation, working with Form_Field or Form_Input components, or implementing polymorphic form fields.
---

# RSX Form Components

## Core Form Structure

Forms use `<Rsx_Form>` with automatic data binding. **`$data` present = edit form; `$data` absent = add form.**

**Always open an existing form as a reference before writing a new one.** Form behavior here is abstract: binding, population and error placement are automatic rather than spelled out in the markup the way plain HTML spells them out. Reading a comparable form in the codebase and copying its patterns is faster and more reliable than reasoning the wiring out from first principles.

**Edit form** (values bind from `$data` by matching `$name`):

```jqhtml
<Rsx_Form $data="<%= JSON.stringify(this.data.form_data) %>"
          $controller="My_Controller" $method="save">
  <Hidden_Input $name="id" />
  <Form_Field $label="Email" $required=true>
    <Text_Input $name="email" $type="email" $max_length=My_Model.field_length('email') />
  </Form_Field>
  <Form_Field $label="Notes">
    <Text_Input $name="notes" $type="textarea" $max_length=-1 $rows="3" />
  </Form_Field>
</Rsx_Form>
```

**Add form** (no `$data` - fields start empty):

```jqhtml
<Rsx_Form $controller="My_Controller" $method="add">
  <Form_Field $label="Name" $required=true>
    <Text_Input $name="name" $max_length=My_Model.field_length('name') $placeholder="Enter name" />
  </Form_Field>
</Rsx_Form>
```

**Two binding rules:** `$name` goes on the INPUT component, never on `Form_Field`; and **NEVER** set `$value` on an input - the form sets it from `$data`.

## Field Components

| Component | Purpose |
|-----------|---------|
| `Form_Field` | Standard formatted field with label, errors, help text |
| `Hidden_Input` | Single-tag hidden input (no `Form_Field` wrapper) |
| `Form_Field_Abstract` | Base class for custom formatting (advanced) |

## Input Components

Live components under `rsx/theme/components/inputs/`:

| Component | Usage |
|-----------|-------|
| `Text_Input` | text, email, url, tel, number, date, textarea |
| `Select_Input` | Dropdown (TomSelect) with options array |
| `Select_With_Description_Input` | Dropdown with a description line per option |
| `Select_Ajax_Input` | Dropdown whose options load from a controller |
| `Select_Country_Input` / `Select_State_Input` | Country / state pickers |
| `Select_User_Role_Input` | Role picker (app-level) |
| `Checkbox_Input` | Single checkbox |
| `Checkbox_Multiselect_Input` | Checkbox group returning an array |
| `Hidden_Input` | Hidden value |
| `Profile_Photo_Input` | Photo upload/crop |
| `Repeater_Simple_Input` | Repeating simple values |
| `Wysiwyg_Input` | Rich text editor (Quill) |

### Text_Input Attributes

`$max_length` is **REQUIRED**: `Model.field_length('column')` for database-driven limits, a numeric value for custom limits, or `-1` for unlimited.

```jqhtml
<Text_Input $name="email" $type="email" $placeholder="user@example.com" $max_length=User_Model.field_length('email') />
<Text_Input $name="notes" $type="textarea" $rows="5" $max_length=-1 />
<Text_Input $name="qty" $type="number" $min="0" $max="100" $max_length=10 />
<Text_Input $name="handle" $prefix="@" $placeholder="username" $max_length=User_Model.field_length('handle') />
```

### Select_Input Formats

```jqhtml
<%-- Simple array --%>
<Select_Input $options="<%= JSON.stringify(['Option 1', 'Option 2']) %>" />

<%-- Value/label objects --%>
<Select_Input $options="<%= JSON.stringify([
    {value: 'opt1', label: 'Option 1'},
    {value: 'opt2', label: 'Option 2'}
]) %>" />

<%-- From model enum --%>
<Select_Input $options="<%= JSON.stringify(Project_Model.status_id__enum_select()) %>" />
```

---

## Disabled Fields

Use `$disabled=true` on input components. Unlike standard HTML, disabled fields still return values via `vals()` (useful for read-only data that should be submitted).

```jqhtml
<Text_Input $type="email" $disabled=true />
<Select_Input $options="..." $disabled=true />
```

---

## Multi-Column Layouts

Use Bootstrap grid for multi-column field layouts:

```jqhtml
<div class="row">
    <div class="col-md-6">
        <Form_Field $label="First Name">
            <Text_Input $name="first_name" />
        </Form_Field>
    </div>
    <div class="col-md-6">
        <Form_Field $label="Last Name">
            <Text_Input $name="last_name" />
        </Form_Field>
    </div>
</div>
```

---

## The vals() Dual-Mode Pattern

Form components implement `vals()` for get/set:

```javascript
class My_Form extends Component {
    vals(values) {
        if (values) {
            // Setter - populate form
            this.$sid('name').val(values.name || '');
            return null;
        } else {
            // Getter - extract values
            return {name: this.$sid('name').val()};
        }
    }
}
```

---

## Form Validation

Apply server-side validation errors:

```javascript
const response = await Controller.save(form.vals());
if (response.errors) {
    Form_Utils.apply_form_errors(form.$, response.errors);
}
```

Errors match by `name` attribute on form fields. The endpoint side is `response_form_error('Validation failed', ['title' => 'Required'])` - the message becomes the form-level error and each key lands inline on the input with that `$name`. `Rsx_Form` displays them itself; `Form_Utils.apply_form_errors()` is for when you handle the response manually. Full contract: skill `rspade:ajax-error-handling`.

---

## Action/Controller Pattern

Forms follow load/save mirroring traditional Laravel:

**Action (loads data):**
```javascript
on_create() {
    this.data.form_data = { title: '', status_id: Model.STATUS_ACTIVE };
    this.data.is_edit = !!this.args.id;
}
async on_load() {
    if (!this.data.is_edit) return;
    const record = await My_Model.fetch(this.args.id);
    this.data.form_data = { id: record.id, title: record.title };
}
```

**Controller (saves data):**
```php
#[Ajax_Endpoint]
#[Auth('can_manage_projects')]   // #[Auth] is MANDATORY on every endpoint (manifest build fails without it)
public static function save(Request $request, array $params = []) {
    if (empty($params['title'])) {
        return response_form_error('Validation failed', ['title' => 'Required']);
    }
    $record = $params['id'] ? My_Model::find($params['id']) : new My_Model();
    $record->title = $params['title'];
    $record->save();
    return ['redirect' => Rsx::Route('View_Action', $record->id)];
}
```

**Key principles:**
- `form_data` must be serializable (plain objects, no models)
- Keep load/save in same controller for field alignment
- `on_load()` loads data, `on_ready()` is UI-only

---

## Repeater Fields

For arrays of values (relationships, multiple items):

**Simple repeaters (array of IDs):**
```javascript
// form_data
this.data.form_data = {
    client_ids: [1, 5, 12],
};

// Controller receives
$params['client_ids']  // [1, 5, 12]

// Sync
$project->clients()->sync($params['client_ids'] ?? []);
```

**Complex repeaters (array of objects):**
```javascript
// form_data
this.data.form_data = {
    team_members: [
        {user_id: 1, role_id: 2},
        {user_id: 5, role_id: 1},
    ],
};

// Controller receives
$params['team_members']  // [{user_id: 1, role_id: 2}, ...]

// Sync with pivot data
$project->team()->detach();
foreach ($params['team_members'] ?? [] as $member) {
    $project->team()->attach($member['user_id'], [
        'role_id' => $member['role_id'],
    ]);
}
```

---

## Test Data (Debug Mode)

Widgets can implement `seed()` for debug mode test data. Rsx_Form displays "Fill Test Data" button when `window.rsxapp.debug` is true.

```jqhtml
<Text_Input $seeder="company_name" />
<Text_Input $seeder="email" />
<Text_Input $seeder="phone" />
```

---

## Creating Custom Input Components

Extend `Form_Input_Abstract`:

```javascript
class My_Custom_Input extends Form_Input_Abstract {
    on_create() {
        // NO on_load() - never use this.data
    }

    on_ready() {
        // Render elements EMPTY - form calls val(value) to populate AFTER render
    }

    // Required: get/set value
    val(value) {
        if (value !== undefined) {
            // Set value
            this.$sid('input').val(value);
        } else {
            // Get value
            return this.$sid('input').val();
        }
    }
}
```

Reference implementations: `Select_Input`, `Text_Input`, `Checkbox_Input`. Full authoring contract: skill `rspade:form-input`.

Reference form (a real, working edit form): `/rsx/app/frontend/settings/user_management/edit_user/`.

---

## Polymorphic Form Fields

For fields that can reference multiple model types:

```php
use App\RSpade\Core\Polymorphic_Field_Helper;

$eventable = Polymorphic_Field_Helper::parse($params['eventable'], [
    Contact_Model::class,
    Project_Model::class,
]);

if ($error = $eventable->validate('Please select an entity')) {
    $errors['eventable'] = $error;
}

$model->eventable_type = $eventable->model;
$model->eventable_id = $eventable->id;
```

Client submits: `{"model":"Contact_Model","id":123}`. Always use `Model::class` for the whitelist.

## More Information

Details: `php artisan rsx:man form_conventions`, `php artisan rsx:man form_input`, `php artisan rsx:man datetime_inputs`
