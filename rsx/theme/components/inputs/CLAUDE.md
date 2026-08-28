# Form Input Components

## Overview

Form input components extend `Form_Input_Abstract` and provide the building blocks for RSX forms. The base class implements a template method pattern that handles value buffering, events, and the val() interface. Concrete classes only need to implement how to get and set values.

## Core Contract: Timing Indifference

**Callers must never care about component lifecycle timing.**

`.val(value)` must produce identical results whether called:
- Before the component initializes (buffered, applied at `_mark_ready()`)
- After the component initializes (applied immediately via `_set_value()`)
- At any point during the component's lifecycle

This is non-negotiable. If a caller needs to use `await component.ready()` or timing tricks to make `val()` work, **the component is broken**.

**Implementation requirement**: Your `_set_value()` must work identically whether called during initial value application (via `_mark_ready()`) or later (via direct `val()` calls). If your implementation relies on initialization state or timing, you've violated this contract.

## Template Method Pattern

The base class (`Form_Input_Abstract`, framework core) handles:
- Pre-initialization value buffering (`_pending_value` + a `_has_pending` BOOLEAN, so
  `null`, `0`, `false` and `''` are all faithfully buffered - `null` is a real value)
- The complete `val()` getter/setter logic
- `trigger('val', value)` on every value change
- Applying buffered values via `_mark_ready()`
- `_notify_input(value)` - the ONE way to announce a user change (fires `'input'` then `'val'`)

Concrete classes implement:
- `_get_value()` - How to read the current value (REQUIRED)
- `_set_value(value)` - How to write a value (REQUIRED)
- `_validate(value)` - OPTIONAL, and almost never used (see below)
- Initialization: call `_mark_ready()` at the earliest moment a `_set_value()` would
  stick, and wire user interaction to `_notify_input()`

## Minimal Implementation

```javascript
class My_Custom_Input extends Form_Input_Abstract {
    _get_value() {
        return this.$sid('input').val();
    }

    _set_value(value) {
        this.$sid('input').val(value || '');
    }

    on_render() {
        this._mark_ready();

        const that = this;
        this.$sid('input').on('input', function() {
            that._notify_input(that.val());
        });
    }
}
```

That's it. No `on_create()` for buffering state, no `val()` method, no manual
`trigger('val')` in the setter, and no hand-triggered event pair.

## Contract Requirements

### 1. Extend Form_Input_Abstract

```javascript
class My_Custom_Input extends Form_Input_Abstract {
    // ...
}
```

The jqhtml framework automatically adds `.Form_Input_Abstract` class to the DOM element. Do NOT manually add `class="Widget"` or similar marker classes.

### 2. Implement _get_value() and _set_value()

These are the only required methods:

```javascript
_get_value() {
    // Return the current value from DOM
    return this.$sid('input').val();
}

_set_value(value) {
    // Set the value on DOM
    this.$sid('input').val(value || '');
}
```

### 3. Handle String/Number Value Coercion

Input components must accept both string and numeric values. A select with option values `"3"`, `"5"`, `"9"` must work with `.val(3)`, `.val("3")`, or `.val('3')`.

**For selects and inputs with discrete values**: Use loose comparison (`==`) or convert to string before comparison:
```javascript
_set_value(value) {
    // Convert to string for comparison with option values
    const str_value = value == null ? '' : str(value);
    this.tom_select.setValue(str_value, true);
}
```

**For text inputs**: HTML inputs handle this automatically via jQuery's `.val()`.

**For checkboxes**: Already handle multiple truthy representations (`1`, `'1'`, `true`).

### 4. Call _mark_ready() at the earliest writable moment

`_mark_ready()` applies any buffered value and marks the component as initialized.
Initialize in `on_render()` whenever the input's DOM is self-contained - the input
becomes editable as early as possible - and use `on_ready()` only when initialization
genuinely depends on child components or an async library:

```javascript
on_render() {
    this._mark_ready();  // Apply buffered value, set _is_ready = true
    // ... setup event handlers
}
```

For async initialization (e.g., waiting for external libraries), call `_mark_ready()` when actually ready:

```javascript
on_ready() {
    const that = this;
    quill_ready(function() {
        that._initialize_quill();
        that._mark_ready();  // Called AFTER quill is ready
    });
}
```

### 5. Announce user interaction with _notify_input()

When the USER changes the value, call `_notify_input()`. It fires `'input'` then
`'val'`, in that order:

```javascript
this.$sid('input').on('input', function() {
    that._notify_input(that.val());
});
```

Hand-triggering the pair is a defect: the ordering and the pairing belong to the base
class, and a call site that fires only one of them silently breaks every listener that
expects the other.

### 6. Template Requirements

- Templates render elements EMPTY (no value attribute)
- Forms populate values via `val()` after render
- Do NOT use `this.data` - inputs have no `on_load()`

```jqhtml
<!-- CORRECT -->
<Define:My_Input>
  <input type="text" $sid="input" />
</Define:My_Input>

<!-- WRONG - do not set value in template -->
<Define:My_Input>
  <input type="text" $sid="input" value="<%= this.data.value %>" />
</Define:My_Input>
```

## Event System

Two events serve different purposes:

**`input` event** - User interaction only:
```javascript
this.sid('country').on('input', (component, value) => {
    // Only fires when user selects, not when form populates
    this.reload_states(value);
});
```

**`val` event** - ALL value changes:
```javascript
// Because jqhtml triggers already-fired events on late registration,
// this callback fires IMMEDIATELY with current value, then on every change
this.sid('amount').on('val', (component, value) => {
    this.update_total(value);
});
```

### 7. Do NOT write client-side validation

An input has a `_validate(value)` seam, and almost every input should keep the
inherited no-op forever.

**Validation lives on the server, once.** A client check that duplicates a server rule
MASKS the absence of the server rule: blank never reaches the endpoint, the missing
server validator is never exercised, and the gap surfaces only when an API caller or a
script hits the endpoint directly - silently, in production. The round trip is fast
enough that pressing Submit and rendering the server's message IS the responsive UX,
and the rule then exists in exactly one place.

`_validate()` exists ONLY for a constraint whose invalid state cannot even be
EXPRESSED to the server - a pick-at-most-two multiselect where a third selection is
unrepresentable in the payload, a structured value that cannot serialize when
malformed. Never for required, format, length or range. And even where it is
legitimate, prefer interaction design: the best pick-at-most-two multiselect simply
refuses the third click, and needs no message at all.

## Extending Other Inputs

When extending another input (e.g., `Select_Ajax_Input` extends `Select_Input`):

```javascript
class Select_Ajax_Input extends Select_Input {
    async on_load() {
        // Load options from Ajax
        this.data.select_values = await Controller.get_options();
    }

    on_ready() {
        // Parent sets up TomSelect and calls _mark_ready()
        super.on_ready();
    }

    // _get_value() and _set_value() inherited from Select_Input
}
```

## Reference Implementations

| Component | Notes |
|-----------|-------|
| `text/text_input.js` | Simple text input - minimal implementation |
| `select/select_input.js` | Dropdown with TomSelect |
| `checkbox/checkbox_input.js` | Boolean with configurable checked/unchecked values |
| `wysiwyg/wysiwyg_input.js` | Async initialization |

## Text_Input $max_length Requirement

`Text_Input` requires the `$max_length` argument to enforce character limits tied to database schema:

```html
<!-- Database-driven limit (recommended) -->
<Text_Input $name="email" $max_length=User_Model.field_length('email') />

<!-- Custom limit -->
<Text_Input $name="search" $max_length=100 />

<!-- Unlimited (use sparingly) -->
<Text_Input $name="notes" $type="textarea" $max_length=-1 />
```

**Values:**
- Positive number: Sets HTML `maxlength` attribute
- `-1`: Unlimited (no maxlength applied)
- Undefined: Console error with guidance

**Subclasses are exempt.** Components extending `Text_Input` (like `Phone_Text_Input`, `Currency_Input`) bypass this check since they have intrinsic limits.

The `field_length()` API is auto-generated from database schema:
```javascript
User_Model.field_length('email')    // 255 (varchar length)
User_Model.field_length('id')       // null (not a varchar)
```

## Common Mistakes

1. **Implementing val()** - Don't. Use `_get_value()` and `_set_value()` instead.

2. **Forgetting _mark_ready()** - Without it a buffered value is never applied.

3. **Using `this.data`** - Inputs have no `on_load()`, so `this.data` is always `{}`.

4. **Adding `class="Widget"`** - Not needed, `.Form_Input_Abstract` is automatic.

5. **Hand-triggering 'input' + 'val'** - Call `_notify_input(value)` instead.

6. **Calling _mark_ready() too early** - For async init, wait until actually ready.

7. **Writing a client-side required/format/length check** - that rule is the server's.

8. **Treating `null` as "no value"** - the buffer tracks presence with a boolean, so
   `val(null)` buffers and applies `null`. Do not reintroduce a null sentinel.

## See Also

- `php artisan rsx:man form_input` - Complete documentation
- The base class ships with the framework (`Core/Forms/Form_Input_Abstract.js`) and
  reaches every bundle - an application never carries a copy of it.
- `rsx/theme/components/forms/` - Form_Field, the presentational field wrapper
