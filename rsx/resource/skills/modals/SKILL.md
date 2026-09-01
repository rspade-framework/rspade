---
name: modals
description: "Creating modal dialogs in RSX - the positional-argument basic dialogs, Modal.form() as chrome around an <Rsx_Form>, Modal.show({buttons}) for a dialog with no endpoint, and modal classes extending Modal_Abstract. Use when implementing Modal.alert / confirm / prompt / select / form / show, writing an add or edit modal body component, deciding between Modal.form and Modal.show, or hitting \"found no <Rsx_Form> inside that component\", a dialog that closes and discards the user's input, or a modal that will not close after submit."
---

# Modal dialogs in this application

> **Living skill.** This skill ships with the template application and is yours. It describes
> the CURRENT state of `rsx/lib/modal/`; the directory file `rsx/lib/modal/CLAUDE.md` is its
> companion. When this feature changes, update this skill and that file in the same pass.

The dialog components themselves - `Modal`, `Modal_Abstract`, `Rsx_Modal` - live in
`rsx/lib/modal/` and are this application's code. The form they host is framework core:
skill `rspade:form-engine`.

## Built-in Dialog Types

All modal methods are async and return appropriate values:

| Method | Returns | Description |
|--------|---------|-------------|
| `Modal.alert(body)` | `void` | Simple notification |
| `Modal.alert(title, body, buttonLabel?)` | `void` | Alert with title |
| `Modal.confirm(body)` | `boolean` | Yes/no confirmation |
| `Modal.confirm(title, body, confirmLabel?, cancelLabel?)` | `boolean` | Confirmation with labels |
| `Modal.prompt(body)` | `string\|false` | Text input |
| `Modal.prompt(title, body, default?, multiline?)` | `string\|false` | Prompt with options |
| `Modal.select(body, options)` | `string\|false` | Dropdown selection |
| `Modal.select(title, body, options, default?, placeholder?)` | `string\|false` | Select with options |
| `Modal.error(error, title?)` | `void` | Error with red styling |
| `Modal.unclosable(title, body)` | `void` | Modal user cannot close |

Also available: `Modal.show(options)` (custom buttons), `Modal.form(options)`, `Modal.close()`, `Modal.fatal_error(error, title?)`.

## ARGUMENT OVERLOADING

**Basic dialogs take positional arguments, NOT an options object** (only `Modal.show()` / `Modal.form()` / `Modal.custom()` take an options object).

**1 arg = body only (default title). 2+ args = first arg is TITLE, second is BODY.** This applies to `alert()`, `confirm()` and `prompt()`. Easy to get backwards - the title shifts to arg 1 when you add a second argument.

```javascript
await Modal.alert('Message');                           // body only (title defaults)
await Modal.alert('Title', 'Message body');             // title + body
await Modal.alert('Title', 'Message body', 'OK');       // title + body + button label

const ok = await Modal.confirm('Delete this?');         // body only
const ok = await Modal.confirm('Delete Item', 'Are you sure?');   // title + body

const name = await Modal.prompt('Enter name:');                   // body only
const name = await Modal.prompt('User Name', 'Enter your name:', 'default value');
```

## Basic Usage Examples

```javascript
// Simple alert
await Modal.alert("File saved successfully");

// Alert with title
await Modal.alert("Success", "Your changes have been saved.");

// Confirmation
if (await Modal.confirm("Are you sure you want to delete this item?")) {
    await Controller.delete(id);
}

// Confirmation with custom labels
const confirmed = await Modal.confirm(
    "Delete Project",
    "This will permanently delete the project.\n\nThis action cannot be undone.",
    "Delete",      // confirm button label
    "Keep Project" // cancel button label
);

// Text prompt
const name = await Modal.prompt("Enter your name:");
if (name) {
    // User entered something
}

// Multiline prompt
const notes = await Modal.prompt("Notes", "Enter description:", "", true);

// Selection dropdown
const choice = await Modal.select("Choose an option:", [
    {value: 'a', label: 'Option A'},
    {value: 'b', label: 'Option B'}
]);

// Unclosable modal (for critical operations)
Modal.unclosable("Processing", "Please wait...");
await long_operation();
await Modal.close();  // Must close programmatically
```

**Text formatting**: Use `\n\n` for paragraph breaks in modal body text. Single `\n` for line breaks.

---

## Form Modals

**A modal is CHROME around a form.** It has no submission pipeline of its own: it
renders the component, finds the `<Rsx_Form>` inside it, and wires the primary button to
THAT form's `submit()`. The endpoint comes from the form's own `$controller`/`$method` -
the single place an endpoint is ever named. The form contract itself is skill
`rspade:form-engine`.

```javascript
const result = await Modal.form({
    title: 'Add API Key',
    component: 'Add_Api_Key_Modal_Form',   // its template contains an <Rsx_Form>
    component_args: {},
});

if (result) {
    // Saved. `result` is exactly what the endpoint returned.
}
```

Outcomes:

- **Success** closes the dialog and resolves with the server result (an empty success
  resolves `true`, so `if (result)` reads as "submitted").
- **Failure** keeps it open, errors already rendered.
- **Cancel / dismiss** resolves `false`.

### The hosted component

Its template wraps an `<Rsx_Form>`, and in the common case it needs **no JavaScript class
at all** - no `vals()` delegate, no `render_error()` delegate, no controller call, no
client-side validation:

```jqhtml
<Define:Add_Api_Key_Modal_Form>
  <Rsx_Form $controller="Frontend_Settings_Api_Keys_Controller" $method="create_key">

    <Form_Errors />

    <Form_Field $label="Key Name" $required=true>
      <Text_Input $name="name" $max_length=255 $autofocus=true />
    </Form_Field>

  </Rsx_Form>
</Define:Add_Api_Key_Modal_Form>
```

Requirements:

1. Exactly one `<Rsx_Form>`, carrying `$controller` + `$method`.
2. Exactly one `<Form_Errors />`, placed where the layout wants the failure feedback.
3. `$name` on every input (never on `Form_Field`), and never `$value`.
4. No `vals()`, no `render_error()`, no `on_submit`, no client-side validation.

A body with no form raises *"Modal.form({component: 'X'}) found no `<Rsx_Form>` inside
that component. A dialog with no form belongs on Modal.show({buttons})."*

Template-only worked example:
`rsx/app/frontend/settings/api_keys/add_api_key_modal_form.jqhtml`

### The two hooks, for the non-standard minority

```javascript
await Modal.form({
    title: 'Log time',
    component: 'Log_Time_Modal_Form',

    // Adjust the payload, throw a {field: message} object to render it as
    // validation, or return false to abort SILENTLY - the seam for interaction
    // guards, because an "are you sure?" the user declined is not an error.
    before_submit: (vals, form) => ({...vals, minutes: to_minutes(vals.duration)}),

    // Side effects before the dialog closes.
    on_success: (result, form) => Timer.stop(),
});
```

### Edit modals: the record arrives after the dialog opens

The dialog opens **immediately** and the body mounts inside it, so an edit form starts
its own fetch behind its own loading overlay. Nothing is fetched before `Modal.form()`.
The body IS the loader, and that needs a JS class - three hooks, each for a definitional
reason:

```javascript
class Edit_User_Modal_Form extends Component {
    // Earliest legal moment: the fetch races the children's own initialization
    // instead of queueing behind it. Start the promise here; never await it here.
    on_create() {
        this._record = Users_Controller.get_user_for_edit({user_id: int(this.args.user_id)});
    }

    // Renders REBUILD the DOM, so the overlay is STATE, re-armed every render.
    on_render() {
        if (!this._record_settled) {
            this._get_form()?.set_loading(true);
        }
    }

    // populate() applies the values and clears the overlay in its finally, on
    // success and on rejection alike. Guarded, because populate() can re-render.
    on_ready() {
        if (this._populate_started) return;
        this._populate_started = true;
        this._populate();
    }

    async _populate() {
        const form = this._get_form();
        try {
            await form.populate(this._record);
        } catch (e) {
            this._record_settled = true;
            await form.render_error(e);
            return;
        }
        this._record_settled = true;
    }

    _get_form() {
        const $form = this.$.find('.Rsx_Form').first();
        return $form.exists() ? $form.component() : null;
    }
}
```

The settled flag is an **instance property**, deliberately not `this.data`: `this.data` is
the maybe-cached result of `on_load()`, and a cached "already settled" would lie on the
next open while the real fetch was still in flight.

The template renders the REAL form unconditionally - never a placeholder. While the
record is in flight the form **refuses to submit**: blank is a value, so a submit racing
the fetch would validly clear every field the data had not reached.

Worked example:
`rsx/app/frontend/settings/group_management/edit_group/edit_group_modal_form.js`

### Error handling: there is nothing to write

- **Validation failures** (`response_form_error()` from the endpoint) pin each message
  under the input its key names; the summary renders in `<Form_Errors />`.
- **Everything else** - not found, unauthorized, a network failure - renders as a single
  alert in the same container.
- Either way `submit()` resolves `false`, so the dialog stays open.

Never wrap a submit in `try/catch` to "handle" a validation error: swallowing it before
the renderer sees it is exactly how a failed save comes to look like a successful one.

---

## A dialog with no form is Modal.show, not Modal.form

If the dialog collects a choice and the CALLER acts on it - a picker, a selection table,
a wizard step that persists nothing - there is no endpoint for a form to name. Use
`Modal.show({buttons})` and give the body component a **named accessor**
(`get_selection()`), not a `vals()`:

```javascript
const $body = $('<div>');
let body = null;

const picked = await Modal.show({
    title: 'Choose contacts',
    body: $body,
    buttons: [
        {label: 'Cancel', value: false, class: 'btn-secondary'},
        {
            label: 'Continue',
            class: 'btn-primary',
            default: true,
            callback: () => {
                const selection = body.get_selection();
                if (!selection.length) {
                    body.show_empty_selection_note();
                    return false;   // ONLY a literal false keeps the dialog open
                }
                return selection;
            },
        },
    ],
    on_show: () => {
        body = $body.component('Contact_Picker_Body', {contacts}).component();
    },
});
```

**`return null` CLOSES the dialog.** Only a literal `false` holds it open. A callback
returning `null` "to keep the modal open" silently discards whatever the user entered.

`on_show` fires once the dialog is on screen, and is where a body component is mounted
into a jQuery body element.

---

## Modal Classes (Reusable Modals)

A modal class is an **orchestration layer**: it shows one dialog and returns its result.
It holds no validation and no business logic - those live in the endpoint.

```javascript
class Add_User_Modal extends Modal_Abstract {
    static async show() {
        const result = await Modal.form({
            title: 'Add User',
            component: 'Add_User_Modal_Form',
        });

        return result || false;
    }
}

class Edit_User_Modal extends Modal_Abstract {
    static async show(user_id) {
        // Nothing is fetched here - the body component loads the record itself,
        // behind the form's own loading overlay.
        const result = await Modal.form({
            title: 'Edit User',
            component: 'Edit_User_Modal_Form',
            component_args: {user_id: int(user_id)},
        });

        return result || false;
    }
}
```

That is the whole class: no `on_submit`, no `vals()`, no `try/catch`, no error rendering.

### Usage Pattern

```javascript
const user = await Add_User_Modal.show();
if (user) {
    $('.Users_DataGrid').component().reload();
    await Assign_Role_Modal.show(user.id);      // page JS chains, not modal classes
}
```

**Pattern**: extend `Modal_Abstract`, implement static `show()`, return data or `false`.

---

## Modal Options

`Modal.form()`:

```javascript
await Modal.form({
    title: 'Form Title',
    component: 'Form_Component',   // required; its template holds the <Rsx_Form>
    component_args: {},
    submit_label: 'Save',
    cancel_label: 'Cancel',
    max_width: 800,                // pixels (default 800)
    closable: true,                // ESC / backdrop / X (default true)
    before_submit: (vals, form) => {},   // adjust the payload, or false to abort
    on_success: (result, form) => {},    // side effects before the dialog closes
});
```

`Modal.show()` (custom dialogs):

```javascript
await Modal.show({
    title: 'Choose Action',
    body: 'What would you like to do?',   // string, HTML, or jQuery element
    max_width: 500,
    closable: true,
    buttons: [
        {label: 'Cancel', value: false, class: 'btn-secondary'},
        {label: 'Continue', value: true, class: 'btn-primary', default: true},
    ],
    on_show: () => {},
});
```

---

## Modal Queuing

Multiple simultaneous modal requests are queued and shown sequentially:

```javascript
// All three modals queued and shown one after another
const p1 = Modal.alert("First");
const p2 = Modal.alert("Second");
const p3 = Modal.alert("Third");

await Promise.all([p1, p2, p3]);
```

Backdrop persists across queued modals with 500ms delay between.

---

## Best Practices

1. **Use appropriate type**: `alert()` for info, `confirm()` for decisions, `form()` when an endpoint is being called, `show({buttons})` when one is not
2. **Let the form own the submission**: `Modal.form({title, component})` and nothing else
3. **Only a literal `false`** from a button callback keeps a dialog open; `null` closes it
4. **Handle cancellations**: always check for the `false` return value
5. **Modal classes don't chain**: page JS orchestrates sequences, not modal classes
6. **No UI updates in modals**: page JS reloads grids after a dialog resolves
7. **Loading states**: `Modal.unclosable()` + `Modal.close()` for long operations

## More Information

- `php artisan rsx:man modals` - complete documentation
- Skill `rspade:form-engine` - the framework form contract a modal hosts
- Skill `form-components` - `Form_Field` and the input roster a modal body is built from
- `rsx/lib/modal/CLAUDE.md` - the living notes beside the code

## Troubleshooting

| Symptom | Cause |
|---|---|
| "found no `<Rsx_Form>` inside that component" | The body has no form; it belongs on `Modal.show({buttons})` |
| The dialog closed and threw the input away | A button callback returned `null`; return `false` |
| The dialog will not close after submit | The endpoint returned a validation error - look at the fields |
| Validation errors do not show | No `<Form_Errors />` (it throws), or the endpoint used `response_error(ERROR_VALIDATION, 'string')` instead of `response_form_error()` |
| An edit modal shows an empty form | `populate()` never ran, or the settled flag was stored in `this.data` and came back cached |
