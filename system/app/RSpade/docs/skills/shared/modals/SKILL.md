---
name: modals
description: Creating modal dialogs in RSX including alerts, confirms, prompts, selects, and form modals. Use when implementing Modal.alert, Modal.confirm, Modal.prompt, Modal.form, creating modal classes extending Modal_Abstract, or building dialog-based user interactions.
---

# RSX Modal System

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

For complex data entry, use `Modal.form()`:

```javascript
const result = await Modal.form({
    title: "Edit User",
    component: "User_Form",           // Component name (must implement vals())
    component_args: {data: user},     // Args passed to component
    max_width: 800,                   // Width in pixels (default: 800)
    on_submit: async (form) => {
        const response = await User_Controller.save(form.vals());
        if (response.errors) {
            Form_Utils.apply_form_errors(form.$, response.errors);
            return false; // Keep modal open
        }
        return response.data; // Close modal and return data
    }
});

if (result) {
    // Modal closed with data
    console.log(result.id);
}
```

### Form Component Requirements

The component used in `Modal.form()` must:
1. Implement `vals()` method (get/set form values)
2. Include `<div $sid="error_container"></div>` for validation errors

```javascript
class User_Form extends Component {
    vals(values) {
        if (values) {
            this.$sid('name').val(values.name || '');
            this.$sid('email').val(values.email || '');
            return null;
        } else {
            return {
                name: this.$sid('name').val(),
                email: this.$sid('email').val()
            };
        }
    }
}
```

---

## Modal Classes (Reusable Modals)

For complex or frequently-used modals, create dedicated classes:

```javascript
class Add_User_Modal extends Modal_Abstract {
    static async show(initial_data = {}) {
        return await Modal.form({
            title: 'Add User',
            component: 'User_Form',
            component_args: {data: initial_data},
            on_submit: async (form) => {
                const response = await User_Controller.create(form.vals());
                if (response.errors) {
                    Form_Utils.apply_form_errors(form.$, response.errors);
                    return false;
                }
                return response.data;
            }
        }) || false;
    }
}

class Edit_User_Modal extends Modal_Abstract {
    static async show(user_id) {
        // Load data first
        const user = await User_Model.fetch(user_id);
        return await Modal.form({
            title: 'Edit User',
            component: 'User_Form',
            component_args: {data: user},
            on_submit: async (form) => {
                const response = await User_Controller.update(form.vals());
                if (response.errors) {
                    Form_Utils.apply_form_errors(form.$, response.errors);
                    return false;
                }
                return response.data;
            }
        }) || false;
    }
}
```

### Usage Pattern

```javascript
// Create new user
const new_user = await Add_User_Modal.show();
if (new_user) {
    grid.reload();
}

// Edit existing user
const updated_user = await Edit_User_Modal.show(user_id);
if (updated_user) {
    // Refresh display
}

// Chain modals (page JS orchestrates, not modals)
const user = await Add_User_Modal.show();
if (user) {
    await Assign_Role_Modal.show(user.id);
}
```

**Pattern**: Extend `Modal_Abstract`, implement static `show()`, return data or `false`.

---

## Modal Options

Options for `Modal.form()`:

```javascript
await Modal.form({
    title: "Form Title",
    component: "Form_Component",
    component_args: {},
    max_width: 800,          // Width in pixels (default: 800)
    closable: true,          // Allow ESC/backdrop/X to close (default: true)
    submit_label: "Save",    // Submit button text
    cancel_label: "Cancel",  // Cancel button text
    on_submit: async (form) => { /* ... */ }
});
```

Options for `Modal.show()` (custom modals):

```javascript
await Modal.show({
    title: "Choose Action",
    body: "What would you like to do?",  // String, HTML, or jQuery element
    max_width: 500,                      // Width in pixels
    closable: true,
    buttons: [
        {label: "Cancel", value: false, class: "btn-secondary"},
        {label: "Continue", value: true, class: "btn-primary", default: true}
    ]
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

1. **Use appropriate type**: `alert()` for info, `confirm()` for decisions, `form()` for complex input
2. **Handle cancellations**: Always check for `false` return value
3. **Modal classes don't chain**: Page JS orchestrates sequences, not modal classes
4. **No UI updates in modals**: Page JS handles post-modal UI updates
5. **Loading states**: Use `Modal.unclosable()` + `Modal.close()` for long operations

## More Information

- `php artisan rsx:man modals` - complete documentation
- `rsx/lib/modal/CLAUDE.md` - implementation details
