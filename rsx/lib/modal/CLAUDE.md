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

Display jqhtml form components with validation:

```javascript
const result = await Modal.form({
    title: "Edit User",
    component: "User_Form",  // jqhtml component name
    component_args: {        // Args passed to component
        data: user_data,
        mode: 'edit'
    },
    on_submit: async (form) => {
        // Get form values
        const values = form.vals();

        // Call API
        const response = await User_Controller.save(values);

        // Handle validation errors
        if (response.errors) {
            Form_Utils.apply_form_errors(form.$, response.errors);
            return false; // Keep modal open
        }

        // Success - close modal and return data
        return response.data;
    }
});

if (result) {
    // User saved successfully
    console.log('User saved:', result);
}
```

## Modal Classes

For complex modals or modals called from multiple places, create dedicated modal classes:

```javascript
// File: /rsx/app/users/modals/add_user_modal.js
class Add_User_Modal extends Modal_Abstract {
    static async show(initial_data = {}) {
        const result = await Modal.form({
            title: 'Add User',
            component: 'Add_User_Form',
            component_args: {
                data: initial_data
            },
            on_submit: async (form) => {
                try {
                    const values = form.vals();

                    // Validate
                    if (!values.email) {
                        Form_Utils.apply_form_errors(form.$, {
                            email: 'Email is required'
                        });
                        return false;
                    }

                    // Save
                    const result = await User_Controller.add_user(values);

                    if (result.errors) {
                        Form_Utils.apply_form_errors(form.$, result.errors);
                        return false;
                    }

                    return result; // Close modal, return data
                } catch (error) {
                    await form.render_error(error);
                    return false; // Keep modal open
                }
            },
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

Form components used in modals must:

1. Extend `Component`
2. Implement `vals(values)` method for getting/setting values
3. Include error container: `<div $id="error_container"></div>`

```javascript
class User_Form extends Component {
    vals(values) {
        if (values) {
            // Setter mode - populate form
            this.$id('name').val(values.name || '');
            this.$id('email').val(values.email || '');
            return null;
        } else {
            // Getter mode - extract values
            return {
                id: this.$id('id').val(),
                name: this.$id('name').val(),
                email: this.$id('email').val()
            };
        }
    }
}
```

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
    component: 'Component_Name',
    component_args: {},
    size: 'lg',              // 'sm', 'md' (default), 'lg', 'xl'
    backdrop: 'static',      // Prevent closing on backdrop click
    keyboard: false,         // Prevent closing on ESC key
    on_submit: async (form) => {},
    on_shown: (modal) => {}, // After modal shown
    on_hidden: () => {}      // After modal hidden
});
```

## Error Handling in Modals

```javascript
on_submit: async (form) => {
    try {
        const values = form.vals();
        const result = await API.save(values);

        if (!result.success) {
            // Display field-specific errors
            if (result.errors) {
                Form_Utils.apply_form_errors(form.$, result.errors);
            }

            // Display general error
            if (result.message) {
                form.$.find('.error-container').html(
                    `<div class="alert alert-danger">${result.message}</div>`
                );
            }

            return false; // Keep modal open
        }

        return result.data;
    } catch (error) {
        // Handle unexpected errors
        console.error('Modal error:', error);
        await form.render_error(error);
        return false;
    }
}
```

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

```javascript
class Edit_Item_Modal extends Modal_Abstract {
    static async show(item_id) {
        // Load current data
        const item = await Item_Model.fetch(item_id);

        const result = await Modal.form({
            title: `Edit ${item.name}`,
            component: 'Item_Form',
            component_args: {
                data: item
            },
            on_submit: async (form) => {
                const values = form.vals();
                values.id = item_id;  // Include ID for update

                const response = await Item_Controller.update(values);

                if (response.errors) {
                    Form_Utils.apply_form_errors(form.$, response.errors);
                    return false;
                }

                return response.data;
            }
        });

        return result || false;
    }
}
```

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

- **Loading state**: Disable buttons during async operations
- **Form state**: Preserve form values on validation errors
- **Error state**: Display errors without closing
- **Success state**: Close and return data

## Best Practices

1. **Use Modal Classes** for reusable modals
2. **Return false to keep modal open** on errors
3. **Return data to close modal** on success
4. **Use Form_Utils.apply_form_errors()** for validation
5. **Chain modals sequentially**, not nested
6. **Place modal classes in feature directories**
7. **Let page JS handle UI updates** after modal actions

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

/rsx/theme/components/modal/
├── modal_abstract.js       # Base class for modal classes
└── CLAUDE.md               # This documentation
```

## Common Issues

### Modal doesn't close after submit
- Ensure `on_submit` returns a truthy value on success
- Return `false` explicitly to keep open

### Form values not preserved on error
- Don't recreate the form component
- Use `Form_Utils.apply_form_errors()` to show errors

### Modal appears behind backdrop
- Check z-index conflicts in CSS
- Ensure no parent has `position: fixed` with lower z-index

### Form validation not showing
- Verify error container exists: `<div $id="error_container"></div>`
- Check error field names match form field names