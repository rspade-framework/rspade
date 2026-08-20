---
name: ajax-error-handling
description: Handling Ajax errors in RSX including the {_success,_ajax_return_value} envelope, error codes, response_error()/response_form_error(), client-side error display, and Form_Utils. Use when implementing error handling for Ajax calls, returning per-field validation errors from an endpoint, deciding whether to wrap something in try/catch or let it bubble, or debugging an Ajax failure from the CLI with rsx:ajax.
---

# RSX Ajax Error Handling

## Response Architecture

RSX returns **HTTP 200 for ALL Ajax responses** (success and errors). Success/failure is encoded in the response body via `_success` field.

```javascript
// Success response
{
    "_success": true,
    "_ajax_return_value": { /* your data */ }
}

// Error response
{
    "_success": false,
    "error_code": "validation|not_found|unauthorized|auth_required|fatal",
    "reason": "User-friendly message",
    "metadata": { /* field errors for validation */ }
}
```

**Rationale**: Batch requests need uniform status codes. Always get parseable response body. Non-200 only means "couldn't reach PHP at all".

---

## Error Codes

| Constant | Purpose |
|----------|---------|
| `Ajax::ERROR_VALIDATION` | Field validation failures |
| `Ajax::ERROR_NOT_FOUND` | Resource not found |
| `Ajax::ERROR_UNAUTHORIZED` | User lacks permission |
| `Ajax::ERROR_AUTH_REQUIRED` | User not logged in |
| `Ajax::ERROR_FATAL` | Uncaught PHP exceptions |

Constants available in both PHP (`Ajax::ERROR_*`) and JavaScript (`Ajax.ERROR_*`).

---

## Server-Side: Returning Errors

Use `response_error()` helper - never return `_success` manually:

```php
#[Ajax_Endpoint]
#[Auth('can_manage_users')]   // MANDATORY - the manifest build fails without a gate
public static function save(Request $request, array $params = []) {
    // Validation error with field-specific messages
    if (empty($params['email'])) {
        return response_error(Ajax::ERROR_VALIDATION, [
            'email' => 'Email is required'
        ]);
    }

    // Not found error
    $user = User_Model::find($params['id']);
    if (!$user) {
        return response_error(Ajax::ERROR_NOT_FOUND, 'User not found');
    }

    // Success - just return data (framework wraps it)
    $user->name = $params['name'];
    $user->save();
    return ['id' => $user->id];
}
```

### `response_form_error()` - the form-shaped shortcut

When the endpoint backs a form, `response_form_error(string $message, array $field_errors = [])` says the same thing more directly than assembling an `ERROR_VALIDATION` payload by hand:

```php
if (empty($params['title'])) {
    return response_form_error('Validation failed', ['title' => 'Required']);
}
```

The message lands as the form-level error; each key in `$field_errors` lands inline on the input with that `$name`. `Form_Utils.apply_form_errors($form.$, response.errors)` is what places them when you handle the response manually.

### Let Exceptions Bubble

Don't wrap database/framework operations in try/catch. Let exceptions bubble to the global handler:

```php
// WRONG - Don't catch framework exceptions
try {
    $user->save();
} catch (Exception $e) {
    return ['error' => $e->getMessage()];
}

// CORRECT - Let it throw
$user->save();  // Framework catches and formats
```

**A security failure is never recoverable.** The catch block that substitutes an unsanitized value is the worst version of this mistake, because the request continues and looks successful:

```php
// WRONG - DISASTER
try { $clean = Sanitizer::sanitize($input); }
catch (Exception $e) { $clean = $input; }

// CORRECT
$clean = Sanitizer::sanitize($input);  // Let it throw
```

Only catch failures you expect and can describe (a file upload, an outbound API call, user input). An exception handler **formats** an error; it never **wraps** one, and it never provides an alternative code path.

**When try/catch IS appropriate**: File uploads, external API calls, user input parsing (expected failures).

---

## Client-Side: Handling Errors

Ajax.js automatically unwraps responses:
- `_success: true` → Promise resolves with `_ajax_return_value`
- `_success: false` → Promise rejects with Error object

```javascript
try {
    const user = await User_Controller.get_user(123);
    console.log(user.name);  // Already unwrapped
} catch (error) {
    console.log(error.code);     // 'validation', 'not_found', etc.
    console.log(error.message);  // User-displayable message
    console.log(error.metadata); // Field errors for validation
}
```

### Automatic Error Display

Uncaught Ajax errors automatically display via `Modal.error()`:

```javascript
// No try/catch - error shows in modal automatically
const user = await User_Controller.get_user(123);
```

---

## Form Error Handling

### With Rsx_Form (Recommended)

```javascript
const result = await Modal.form({
    title: 'Add User',
    component: 'User_Form',
    on_submit: async (form) => {
        try {
            const result = await Controller.save(form.vals());
            return result; // Success - close modal
        } catch (error) {
            await form.render_error(error);
            return false; // Keep modal open
        }
    }
});
```

`form.render_error()` handles all error types:
- **validation**: Shows inline field errors + alert for unmatched errors
- **fatal/network/auth**: Shows error in form's error container

### With Form_Utils (Non-Rsx_Form)

```javascript
try {
    const result = await Controller.save(form_data);
} catch (error) {
    if (error.code === Ajax.ERROR_VALIDATION) {
        Form_Utils.apply_form_errors($form, error.metadata);
    } else {
        Rsx.render_error(error, '#error_container');
    }
}
```

`Form_Utils.apply_form_errors()`:
- Matches errors to fields by `name` attribute
- Adds `.is-invalid` class and inline error text
- Shows alert ONLY for errors that couldn't match to fields

---

## Error Display Methods

### Modal.error() - Critical Errors

```javascript
await Modal.error(error, 'Operation Failed');
```

Red danger modal, can stack over other modals.

### Rsx.render_error() - Container Display

```javascript
Rsx.render_error(error, '#error_container');
Rsx.render_error(error, this.$sid('error'));
```

Displays error in any container element.

---

## Developer vs Production Mode

**Developer Mode** (`IS_DEVELOPER=true`):
- Full exception message
- File path and line number
- SQL queries with parameters
- Stack trace (up to 10 frames)

**Production Mode**:
- Generic message: "An unexpected error occurred"
- No technical details exposed
- Errors logged server-side

---

## Common Patterns

### Simple Validation

```php
#[Ajax_Endpoint]
public static function save(Request $request, array $params = []) {
    $errors = [];

    if (empty($params['email'])) {
        $errors['email'] = 'Email is required';
    }
    if (empty($params['name'])) {
        $errors['name'] = 'Name is required';
    }

    if ($errors) {
        return response_error(Ajax::ERROR_VALIDATION, $errors);
    }

    // ... save logic
}
```

### Check Specific Error Type

```javascript
try {
    const data = await Controller.get_data(id);
} catch (error) {
    if (error.code === Ajax.ERROR_NOT_FOUND) {
        show_not_found_message();
    } else if (error.code === Ajax.ERROR_UNAUTHORIZED) {
        redirect_to_login();
    } else {
        // Let default handler show modal
        throw error;
    }
}
```

## Debugging From the CLI

`rsx:ajax` is the entry point when you need to know whether the ENDPOINT is wrong or the page is:

```bash
php artisan rsx:ajax My_Controller save --args='{"id":1}'          # raw return value
php artisan rsx:ajax My_Controller save --args='{"id":1}' --debug  # full envelope
php artisan rsx:ajax My_Controller list --user=1 --site=1          # with auth/site context
php artisan rsx:ajax My_Controller list --user=admin@example.com   # user by email
```

`--debug` prints the HTTP-like response (`{success, _ajax_return_value, console_debug}`) so you can see the envelope and any `console_debug()` output the endpoint emitted; `--verbose` prefixes the request context. All errors come back as JSON - the command never throws to stderr.

If `rsx:ajax` returns the right data and the page does not show it, the fault is client-side; if it returns the error, you have reproduced the bug without a browser.

## More Information

Details: `php artisan rsx:man ajax_error_handling`
