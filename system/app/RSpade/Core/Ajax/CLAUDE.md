# Ajax - Unified AJAX Handling System

## Overview

The Ajax class consolidates all AJAX-related functionality in one place, providing clear methods for different types of AJAX operations:

- **Internal calls** - Server-side PHP calling API methods directly
- **Browser requests** - Handling incoming AJAX requests from JavaScript
- **Response modes** - Managing JSON vs HTML error responses
- **Future expansion** - External API calls, WebSocket handling

## Method Overview

### Core Methods

```php
// Internal server-side API calls (no HTTP overhead)
Ajax::internal($controller, $action, $params = [], $auth = [])

// Handle incoming browser AJAX requests (/_ajax route)
Ajax::handle_browser_request($request, $params)

// Set response mode for error handlers
Ajax::set_ajax_response_mode(bool $enabled)

// Check current response mode
Ajax::is_ajax_response_mode()

// Backward compatibility (deprecated)
Ajax::call() // Use internal() instead
```

## Usage Examples

### Internal Server-Side Calls

```php
use App\RSpade\Core\Ajax\Ajax;

// Call an API method internally from PHP code
$result = Ajax::internal('User_Controller', 'get_profile', ['user_id' => 123]);
```

This is useful for:
- Service-to-service communication within the application
- Background jobs that need to call API methods
- Testing API methods without HTTP overhead
- Batch processing operations

## How It Works

1. **Controller Discovery**: Uses the Manifest to find the controller class by name
2. **Validation**: Verifies that:
   - The controller exists and extends `Rsx_Controller`
   - The method exists and has the `Ajax_Endpoint` annotation
3. **Execution**: 
   - Calls `pre_dispatch()` if it exists on the controller
   - If pre_dispatch returns null, calls the actual method
4. **Response Handling**:
   - Normal responses are filtered through JSON encode/decode to remove PHP objects
   - Special response types throw specific exceptions

## Browser AJAX Requests

The `/_ajax/:controller/:action` route is handled by `Internal_Api::dispatch()`, which delegates to `Ajax::handle_browser_request()`. This provides a consistent JSON response format for browser JavaScript code.

### Calling from JavaScript

The RSX framework automatically generates JavaScript stub classes for PHP controllers with `Ajax_Endpoint` methods. This enables clean, type-hinted API calls from JavaScript:

```javascript
// Auto-generated stub enables this clean syntax:
const result = await Demo_Index_Controller.hello_world();
console.log(result); // "Hello, world!"

// With parameters:
const profile = await User_Controller.get_profile(123);
console.log(profile.name);
```

The JavaScript stubs are:
- Auto-generated during manifest build for any controller with `Ajax_Endpoint` methods
- Included automatically in bundles when the PHP controller is referenced
- Use the `Ajax.call()` method internally to make the actual AJAX request
- Support arbitrary parameters via rest parameters (`...args`)

Behind the scenes, the stub translates to:
```javascript
class Demo_Index_Controller {
    static async hello_world(...args) {
        return Ajax.call(Rsx.Route('Demo_Index_Controller', 'hello_world'), args);
    }
}
```

Note: `Rsx.Route()` generates type-safe URLs like `/_ajax/Demo_Index_Controller/hello_world`.

### Response Format

Success responses:
```json
{
    "_success": true,
    "_ajax_return_value": { /* method return value */ },
    "console_debug": [ /* optional debug messages */ ]
}
```

Error responses:
```json
{
    "_success": false,
    "error_code": "validation",
    "message": "Please correct the errors below",
    "errors": { "field_name": "Error message" }
}
```

## Exception Handling

For **internal calls** (`Ajax::internal()`), the method throws specific exceptions for different error scenarios:

### Authentication Required
```php
use App\RSpade\Core\Ajax\Exceptions\AjaxAuthRequiredException;

try {
    $result = Ajax::internal('Protected_Controller', 'secure_method');
} catch (AjaxAuthRequiredException $e) {
    // Handle authentication requirement
    echo "Auth required: " . $e->getMessage();
}
```

### Unauthorized Access
```php
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;

try {
    $result = Ajax::internal('Admin_Controller', 'admin_action');
} catch (AjaxUnauthorizedException $e) {
    // Handle authorization failure
    echo "Unauthorized: " . $e->getMessage();
}
```

### Form Validation Errors
```php
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;

try {
    $result = Ajax::internal('User_Controller', 'update_profile', $data);
} catch (AjaxFormErrorException $e) {
    // Extract error details
    $message = $e->getMessage();
    $details = $e->get_details(); // Array of validation errors
    
    foreach ($details as $field => $error) {
        echo "Field $field: $error\n";
    }
}
```

### Fatal Errors
```php
use App\RSpade\Core\Ajax\Exceptions\AjaxFatalErrorException;

try {
    $result = Ajax::internal('Some_Controller', 'problematic_method');
} catch (AjaxFatalErrorException $e) {
    // Handle fatal error
    error_log("Fatal error: " . $e->getMessage());
}
```

## Creating API Methods

To make a method callable via Ajax::internal(), it must:

1. Be in a controller that extends `Rsx_Controller`
2. Have the `Ajax_Endpoint` annotation
3. Accept `Request $request` and `array $params = []` as parameters

Example:
```php
class User_Controller extends Rsx_Controller
{
    #[Ajax_Endpoint]
    public static function get_profile(Request $request, array $params = [])
    {
        $user_id = $params['user_id'] ?? null;

        if (!$user_id) {
            return response_error(Ajax::ERROR_VALIDATION, ['user_id' => 'User ID required']);
        }

        $user = User::find($user_id);

        if (!$user) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'User not found');
        }
        
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email
        ];
    }
}
```

## Response Filtering

Normal responses (arrays, objects) are automatically filtered through `json_decode(json_encode())` to:
- Remove PHP object instances
- Convert objects to associative arrays
- Ensure only serializable data is returned

This prevents memory leaks and ensures clean data structures.

## Error Response Helper

Use `response_error()` with error codes from the `Ajax` class:

```php
return response_error(Ajax::ERROR_VALIDATION, ['field' => 'Error message']);
return response_error(Ajax::ERROR_NOT_FOUND, 'Resource not found');
return response_error(Ajax::ERROR_UNAUTHORIZED);
return response_error(Ajax::ERROR_AUTH_REQUIRED);
return response_error(Ajax::ERROR_FATAL, 'Something went wrong');
```

**Error codes:** `ERROR_VALIDATION`, `ERROR_NOT_FOUND`, `ERROR_UNAUTHORIZED`, `ERROR_AUTH_REQUIRED`, `ERROR_FATAL`, `ERROR_GENERIC`

## Security Considerations

- **Internal calls** (`Ajax::internal()`) bypass HTTP middleware, so authentication/authorization must be handled in the controller methods
- **Browser requests** (`handle_browser_request()`) go through normal HTTP middleware
- Always validate input parameters in your API methods
- Use `pre_dispatch()` in controllers for common security checks

## Testing

The Ajax class is ideal for testing API methods:

```php
class UserApiTest extends TestCase
{
    public function test_get_profile()
    {
        $result = Ajax::internal('User_Controller', 'get_profile', ['user_id' => 1]);
        
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
    }
    
    public function test_get_profile_missing_user()
    {
        $this->expectException(AjaxFormErrorException::class);
        
        Ajax::internal('User_Controller', 'get_profile', ['user_id' => 999999]);
    }
}
```

## Limitations

- The `$auth` parameter is reserved for future implementation of authentication context switching
- Methods must be static (following the RSX pattern)
- Only works with controllers in the manifest scan directories