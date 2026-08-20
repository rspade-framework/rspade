---
name: js-decorators
description: RSX JavaScript decorators including @route, @spa, @layout, @mutex, and custom decorator creation. Use when adding route decorators to actions, understanding decorator restrictions, creating custom decorators, or troubleshooting decorator errors.
---

# RSX JavaScript Decorators

## Overview

RSX uses decorators to enhance static methods. Unlike standard JavaScript, RSX requires explicit whitelisting via `@decorator` marker to prevent arbitrary code injection.

**Key difference**: Only functions marked with `@decorator` can be used as decorators elsewhere.

---

## Common Framework Decorators

### @route - SPA Routing

Defines URL paths for SPA actions:

```javascript
@route('/contacts')
class Contacts_Index_Action extends Spa_Action { }

@route('/contacts/:id')
class Contacts_View_Action extends Spa_Action { }

// Dual routes (add/edit in one action)
@route('/contacts/add')
@route('/contacts/:id/edit')
class Contacts_Edit_Action extends Spa_Action { }
```

### @spa - SPA Bootstrap

Links action to its SPA bootstrap controller:

```javascript
@route('/contacts')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Contacts_Index_Action extends Spa_Action { }
```

### @auth - MANDATORY on every routed action

Every `@route` action declares a gate; the manifest build FAILS without one. `@auth('public')` is the explicit open marker - there is no attribute-free spelling of "open". See the auth-gates documentation for the check vocabulary.

### @layout - Layout Assignment

Assigns layout(s) to action:

```javascript
@route('/contacts')
@layout('Frontend_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Contacts_Index_Action extends Spa_Action { }

// Nested layouts (sublayouts)
@route('/settings/general')
@layout('Frontend_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('can_manage_site_settings')
class Settings_General_Action extends Spa_Action { }
```

### @mutex - Mutual Exclusion

Prevents concurrent execution of async methods:

```javascript
class My_Component extends Component {
    @mutex()
    async save() {
        // Only one save() can run at a time
        await Controller.save(this.vals());
    }
}
```

---

## Decorator Restrictions

### Static Methods Only

Decorators can only be applied to static methods, not instance methods:

```javascript
class Example {
    @myDecorator
    static validMethod() { }      // ✅ Valid

    @myDecorator
    invalidMethod() { }           // ❌ Invalid - instance method
}
```

### No Class Name Identifiers in Parameters

Decorator parameters **must not** use class name identifiers (bundle ordering issues):

```javascript
// WRONG - class identifier as parameter
@some_decorator(User_Model)
class My_Action extends Spa_Action { }

// CORRECT - use string literal
@some_decorator('User_Model')
class My_Action extends Spa_Action { }
```

**Why**: Bundle compilation doesn't guarantee class definition order for decorator parameters.

### Multiple Decorators

Execute in reverse order (bottom to top):

```javascript
@logExecutionTime      // Executes second (outer)
@validateParams        // Executes first (inner)
static transform(data) { }
```

---

## Creating Custom Decorators

### Basic Decorator

```javascript
@decorator
function myCustomDecorator(target, key, descriptor) {
    const original = descriptor.value;
    descriptor.value = function(...args) {
        console.log(`Calling ${key}`);
        return original.apply(this, args);
    };
    return descriptor;
}
```

### Using Custom Decorator

```javascript
class MyClass {
    @myCustomDecorator
    static myMethod() {
        return "result";
    }
}
```

---

## Common Decorator Patterns

### Logging

```javascript
@decorator
function logCalls(target, key, descriptor) {
    const original = descriptor.value;
    descriptor.value = function(...args) {
        console.log(`Calling ${target.name}.${key}`, args);
        const result = original.apply(this, args);
        console.log(`${target.name}.${key} returned`, result);
        return result;
    };
    return descriptor;
}
```

### Async Error Handling

```javascript
@decorator
function catchAsync(target, key, descriptor) {
    const original = descriptor.value;
    descriptor.value = async function(...args) {
        try {
            return await original.apply(this, args);
        } catch (error) {
            console.error(`Error in ${key}:`, error);
            throw error;
        }
    };
    return descriptor;
}
```

### Rate Limiting

```javascript
@decorator
function rateLimit(target, key, descriptor) {
    const calls = new Map();
    const original = descriptor.value;
    descriptor.value = function(...args) {
        const now = Date.now();
        const lastCall = calls.get(key) || 0;
        if (now - lastCall < 1000) {
            throw new Error(`${key} called too frequently`);
        }
        calls.set(key, now);
        return original.apply(this, args);
    };
    return descriptor;
}
```

---

## Decorator Function Signature

```javascript
function myDecorator(target, key, descriptor) {
    // target: The class being decorated
    // key: The method name being decorated
    // descriptor: Property descriptor for the method

    const original = descriptor.value;
    descriptor.value = function(...args) {
        // Pre-execution logic
        const result = original.apply(this, args);
        // Post-execution logic
        return result;
    };
    return descriptor;
}
```

**Important**: Always use `original.apply(this, args)` to preserve context.

---

## Troubleshooting

| Error | Solution |
|-------|----------|
| "Decorator 'X' not whitelisted" | Add `@decorator` marker to function |
| "Decorator 'X' not found" | Ensure decorator loaded before usage |
| "Decorators can only be applied to static methods" | Change to static or remove decorator |

## More Information

Details: `php artisan rsx:man js_decorators`
