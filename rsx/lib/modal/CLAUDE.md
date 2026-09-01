# Modal System

## Overview

RSpade modals provide a unified API for dialogs, forms, and custom content with built-in validation and error handling.

## Basic Dialogs

Simple modal dialogs for user interaction:

```javascript
// Alert dialog
await Modal.alert("File saved successfully");

// Confirmation dialog
if (await Modal.confirm("Are you sure you want to delete this item?")) {
    // User confirmed
}

// Prompt for input
const name = await Modal.prompt("Enter your name:");
if (name) {
    // User entered a value
}
```

## Form Modals

A modal is CHROME around a form. It has no submission pipeline of its own: it renders
the component, finds the `<Rsx_Form>` inside it, and wires the primary button to THAT
form's `submit()`. The endpoint comes from the form's own `$controller`/`$method` -
the single place an endpoint is ever named.

```javascript
const result = await Modal.form({
    title: 'Edit User',
    component: 'Edit_User_Modal_Form',   // its template contains an <Rsx_Form>
    component_args: { user_id },
});

if (result) {
    // Saved. `result` is exactly what the endpoint returned.
}
```

That is the whole common case. The host component usually needs **no JavaScript class
at all** - no `vals()` delegate, no `render_error()` delegate, no controller call, and
no client-side validation. On a failed submit the form has already pinned each server
message under its field and raised its top alert; the dialog simply stays open.

Outcomes:

- **Success** closes the dialog and resolves with the server result.
- **Failure** keeps it open, errors already rendered.
- **Cancel / dismiss** resolves `false`.

### The two hooks, for the non-standard minority

```javascript
await Modal.form({
    title: 'Log time',
    component: 'Log_Time_Modal_Form',

    // Adjust the payload, throw a {field: message} object to render it as
    // validation, or return false to abort SILENTLY - the seam for interaction
    // guards, because an "are you sure?" the user declined is not an error.
    before_submit: (vals, form) => ({ ...vals, minutes: to_minutes(vals.duration) }),

    // Side effects before the dialog closes.
    on_success: (result, form) => Timer.stop(),
});
```

### A dialog with no form is Modal.show, not Modal.form

If the dialog collects a choice and the CALLER acts on it - a picker, a selection
table, a wizard step that persists nothing - there is no form and no endpoint for a
form to name. Use `Modal.show({ buttons })` and give the body component a named
accessor (`get_selection()`), not a `vals()`:

```javascript
const $body = $('<div>');
let body = null;

const picked = await Modal.show({
    title: 'Choose contacts',
    body: $body,
    buttons: [
        { label: 'Cancel', value: false, class: 'btn-secondary' },
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
        body = $body.component('Contact_Picker_Body', { contacts }).component();
    },
});
```

**`return null` CLOSES the dialog.** Only a literal `false` holds it open. A callback
that returns `null` "to keep the modal open" discards whatever the user had entered.

## Modal Classes

For complex modals or modals called from multiple places, create dedicated modal classes:

```javascript
// File: /rsx/app/users/modals/add_user_modal.js
class Add_User_Modal extends Modal_Abstract {
    static async show() {
        const result = await Modal.form({
            title: 'Add User',
            component: 'Add_User_Modal_Form',
        });

        return result || false;
    }
}

// Usage in page JavaScript
$('#add-user-btn').click(async () => {
    const user = await Add_User_Modal.show();
    if (user) {
        // Refresh grid
        $('.Users_DataGrid').component().reload();

        // Chain to another modal
        await Send_Invite_Modal.show(user.id);
    }
});
```

## Form Component Requirements

A form modal's body component is a component whose template wraps an `<Rsx_Form>`:

```jqhtml
<Define:Add_User_Modal_Form>
  <Rsx_Form $controller="Frontend_Users_Controller" $method="add_user">
    <Form_Errors />

    <Form_Field $label="Email" $required=true>
      <Text_Input $name="email" $type="email" $max_length=255 />
    </Form_Field>
  </Rsx_Form>
</Define:Add_User_Modal_Form>
```

Requirements:

1. Exactly one `<Rsx_Form>`, carrying `$controller` + `$method`.
2. Exactly one `<Form_Errors />`, placed where the layout wants the failure feedback.
3. `$name` on every input (never on `Form_Field`).
4. No `vals()`, no `render_error()`, no `on_submit`, and no client-side validation.

### Edit modals: the record arrives after the dialog opens

An edit modal's body IS the loader, and it needs a JS class for that - three hooks,
each for a definitional reason:

```javascript
class Edit_User_Modal_Form extends Component {
    // Earliest legal moment: the fetch races the children's own initialization
    // instead of queueing behind it. Start the promise here; never await it here.
    on_create() {
        this._record = Users_Controller.get_user_for_edit({ user_id: int(this.args.user_id) });
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

The settled flag is an INSTANCE property, deliberately not `this.data`: `this.data` is
the maybe-cached result of `on_load()`, and a cached "already settled" would lie on the
next open while the real fetch was still in flight.

The template still renders the REAL form unconditionally - never a placeholder. The
overlay is what the user waits behind, and it is the form's own structure underneath.

## Custom Modals

For complete control over modal content:

```javascript
const modal = await Modal.show({
    title: 'Custom Modal',
    content: '<div>Custom HTML content</div>',
    buttons: [
        {
            text: 'Cancel',
            class: 'btn btn-secondary',
            action: 'close'  // Built-in action
        },
        {
            text: 'Save',
            class: 'btn btn-primary',
            action: async (modal) => {
                // Custom action
                const data = await process();
                modal.close(data);  // Close and return data
            }
        }
    ]
});
```

## Modal Options

```javascript
Modal.form({
    title: 'Modal Title',
    component: 'Component_Name',   // required; its template holds the <Rsx_Form>
    component_args: {},
    submit_label: 'Save',
    cancel_label: 'Cancel',
    max_width: 800,
    closable: true,
    before_submit: (vals, form) => {},   // adjust the payload / abort with false
    on_success: (result, form) => {},    // side effects before the dialog closes
});
```

## Error Handling in Modals

There is nothing to write. The form owns it:

- **Validation failures** (`response_form_error()` from the endpoint) pin each message
  under the input its key names, and the summary renders in `<Form_Errors />`.
- **Everything else** - not found, unauthorized, a network failure - renders as a
  single alert in the same container.
- Either way `submit()` resolves `false`, so the dialog stays open.

Never wrap a submit in `try/catch` to "handle" a validation error: swallowing it
before the renderer sees it is exactly how a failed save comes to look like a
successful one.

## Modal Patterns

### Sequential Modals

```javascript
// Chain modals in sequence
async function user_onboarding() {
    // Step 1: Create user
    const user = await Add_User_Modal.show();
    if (!user) return;

    // Step 2: Assign role
    const role = await Assign_Role_Modal.show(user.id);
    if (!role) return;

    // Step 3: Send invite
    await Send_Invite_Modal.show(user.id);

    // Refresh UI
    $('.Users_DataGrid').component().reload();
}
```

### Confirmation Before Action

```javascript
$('#delete-btn').click(async () => {
    if (!await Modal.confirm('Delete this item?')) {
        return;
    }

    const result = await Controller.delete(item_id);
    if (result.success) {
        await Modal.alert('Item deleted');
        grid.reload();
    }
});
```

### Edit Modal Pattern

The dialog opens IMMEDIATELY and the body loads its own record behind the form's
loading overlay (see **Edit modals** above). Nothing is fetched before opening:

```javascript
class Edit_Item_Modal extends Modal_Abstract {
    static async show(item_id) {
        const result = await Modal.form({
            title: 'Edit Item',
            component: 'Edit_Item_Modal_Form',
            component_args: { item_id: int(item_id) },
        });

        return result || false;
    }
}
```

While the record is in flight the form refuses to submit. That is not politeness: a
blank field is a VALUE, so a submit racing the fetch would validly clear every field
the data had not reached yet.

## Global Events

Modal lifecycle triggers global Rsx events that can be listened to anywhere:

```javascript
// Listen for any modal opening
Rsx.on('modal_open', (data) => {
    console.log('Modal opened:', data.modal);
    console.log('Options:', data.options);
});

// Listen for any modal closing
Rsx.on('modal_close', (data) => {
    console.log('Modal closed:', data.modal);
    console.log('Result:', data.result);
});
```

### Event Data

**modal_open**:
- `modal` - The Rsx_Modal component instance
- `options` - The options object passed to show/form/alert/etc.

**modal_close**:
- `modal` - The Rsx_Modal component instance that closed
- `result` - The value returned from the modal (button value, form data, or false if cancelled)

### Use Cases

```javascript
// Track modal usage for analytics
Rsx.on('modal_open', (data) => {
    Analytics.track('modal_opened', { title: data.options.title });
});

// Pause background processes while modal is open
Rsx.on('modal_open', () => {
    BackgroundSync.pause();
});

Rsx.on('modal_close', () => {
    BackgroundSync.resume();
});

// Log form submissions
Rsx.on('modal_close', (data) => {
    if (data.result && data.options.component) {
        console.log(`Form ${data.options.component} submitted`);
    }
});
```

### SPA Integration

Modals automatically close on SPA navigation via `spa_dispatch_start` event. This prevents stale modals from persisting across page transitions.

## Modal State Management

Modals automatically manage state:

- **Loading state**: submit buttons enter the submitting state and are inert until the
  round trip completes; an edit form additionally wears a loading overlay, and refuses
  to submit, until its record lands
- **Form state**: values are untouched by a failed submit - the same form instance
  stays on screen with the server's messages rendered into it
- **Error state**: display errors without closing
- **Success state**: close and resolve with the server result

## Best Practices

1. **Use Modal Classes** for reusable modals
2. **Let the form own the submission** - `Modal.form({title, component})` and nothing else
3. **Only a literal `false` keeps a dialog open** from a button callback; `null` closes it
4. **No form? Use `Modal.show({buttons})`**, and name the accessor for what it returns
5. **Chain modals sequentially**, not nested
6. **Place modal classes in feature directories**
7. **Let the caller handle UI updates** after the dialog resolves

## File Organization

```
/rsx/app/users/
├── modals/
│   ├── add_user_modal.js
│   ├── edit_user_modal.js
│   └── invite_user_modal.js
├── forms/
│   ├── user_form.jqhtml
│   └── user_form.js
└── users_controller.php

/rsx/lib/modal/                 # the modal engine itself - application code
├── modal.js                # the static Modal facade: queue, backdrop, body scroll lock
├── modal_abstract.js       # base class for modal classes
├── rsx_modal.js            # the dialog component: sizing, show/close, focus
├── rsx_modal.jqhtml        # its markup
├── rsx_modal.scss          # its look, including the backdrop transition
└── CLAUDE.md               # this documentation
```

## Animation

The entrance animation is DECIDED PER DIALOG in `Modal._process_queue()`: a dialog
animates only on a desktop viewport (>= 1000px) AND when more than a second has
passed since the last modal closed. Sequential dialogs and mobile therefore appear
instantly - the queue never makes the user watch the same fly-in twice in a row.
`show()` passes that decision down as the `animate` internal option.

`Rsx_Modal._fade_in(animate)` is where both paths live. The animated path sets the
dialog to `translate(0, -50px)` at zero opacity, forces a reflow, then adds `.show`
and lets the CSS transition in `rsx_modal.scss` carry it to its resting position. The
instant path suppresses the transition (`transition: none`), paints, and restores it
so the NEXT dialog can animate.

### Changing it

- **The motion itself** - the transform, the easing, the duration - is CSS, in
  `rsx_modal.scss`. Change it there first; the JS only toggles classes and inline
  starting values.
- **When a dialog animates** is the viewport/recency rule in `_process_queue()`.
- **The closing animation** is `Rsx_Modal.close()`, which currently hides the dialog
  and resolves immediately. To fade it out, drive the opacity, `await sleep(ms)` for
  the transition, then hide and remove - and keep the JS duration equal to the CSS
  one, or the dialog is removed mid-fade.
- **The backdrop** fades on its own, through `.modal-backdrop.fade` in
  `rsx_modal.scss`; `Modal._show_backdrop()` / `_hide_backdrop()` are the seams if it
  must be waited on.
- **Auto-focus** happens in `Rsx_Modal._focus_first_input()` on the next frame. If
  you add a long entrance animation, focus lands while the dialog is still moving;
  delay it to match.

Whatever you change, change it in one place per concern: durations that disagree
between the SCSS and the JS produce a dialog that is removed before it finishes
animating, or one that sits still for the tail of a wait.

## Common Issues

### Modal doesn't close after submit
- The endpoint returned a validation error, and the form rendered it - look at the fields
- From a `Modal.show` button callback, only a literal `false` holds the dialog open

### Modal closed and threw the user's input away
- A button callback returned `null` (or `undefined`). Return `false` to keep it open

### Form validation not showing
- The form has no `<Form_Errors />` - add one where the layout wants it
- The endpoint used `response_error(ERROR_VALIDATION, 'a string')`: the second argument
  is METADATA, not a message, so the alert renders empty. Use `response_form_error()`
- The error keys do not match any input's `$name`. Unmatched keys are legitimate - they
  render in the top alert instead

### Modal appears behind backdrop
- Check z-index conflicts in CSS
- Ensure no parent has `position: fixed` with lower z-index

### "Modal.form() found no <Rsx_Form>"
- The body component has no form. If it never submits anything, it belongs on
  `Modal.show({buttons})`