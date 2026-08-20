---
name: form-input
description: Creating custom form input components that extend Form_Input_Abstract. Use when building custom input widgets, implementing _get_value()/_set_value(), handling input events, or integrating inputs with Rsx_Form.
---

# Form Input Component Contract

## Overview

Form input components extend `Form_Input_Abstract` which implements a template method pattern. The base class handles value buffering, events, and the val() interface. Concrete classes only implement how to get and set values.

## Core Contract: Timing Indifference

**Callers must never care about component lifecycle timing.**

`.val(value)` must produce identical results whether called:
- Before the component initializes (buffered, applied at `_mark_ready()`)
- After the component initializes (applied immediately via `_set_value()`)
- At any point during the component's lifecycle

This is non-negotiable. If a caller needs to use `await component.ready()` or timing tricks to make `val()` work, **the component is broken**.

**Implementation requirement**: Your `_set_value()` must work identically whether called during initial value application (via `_mark_ready()`) or later (via direct `val()` calls). If your implementation relies on initialization state or timing, you've violated this contract.

---

## Template Method Pattern

**Base class handles:**
- Pre-initialization value buffering (`_pending_value`, `_is_ready`)
- Complete `val()` getter/setter logic
- Automatic `trigger('val', value)` on all setter calls
- Applying buffered values via `_mark_ready()`

**Concrete classes implement:**
- `_get_value()` - Return current DOM/component value (REQUIRED)
- `_set_value(value)` - Set DOM/component value (REQUIRED)
- `_transform_value(value)` - Transform buffered value for getter (OPTIONAL)
- `on_ready()` - Call `_mark_ready()` and setup user interaction events

---

## Minimal Implementation

```javascript
class My_Custom_Input extends Form_Input_Abstract {
    _get_value() {
        return this.$sid('input').val();
    }

    _set_value(value) {
        this.$sid('input').val(value || '');
    }

    on_ready() {
        this._mark_ready();

        const that = this;
        this.$sid('input').on('input', function() {
            const value = that.val();
            that.trigger('input', value);
            that.trigger('val', value);
        });
    }
}
```

That's it. No `on_create()`, no `val()` method, no buffering logic.

---

## Key Methods

### _get_value() (REQUIRED)

Return the current value from DOM/component:

```javascript
_get_value() {
    return this.$sid('input').val();
}
```

### _set_value(value) (REQUIRED)

Set the value on DOM/component:

```javascript
_set_value(value) {
    this.$sid('input').val(value || '');
}
```

### String/Number Value Coercion

Input components must accept both string and numeric values. A select with options `"3"`, `"5"`, `"9"` must work with `.val(3)` or `.val("3")`.

```javascript
_set_value(value) {
    // Convert to string for comparison with option values
    const str_value = value == null ? '' : str(value);
    this.tom_select.setValue(str_value, true);
}
```

### _mark_ready()

Call in `on_ready()` to apply buffered values and mark component ready:

```javascript
on_ready() {
    this._mark_ready();  // REQUIRED - apply buffered value
    // ... setup event handlers
}
```

For async initialization, call `_mark_ready()` when actually ready:

```javascript
on_ready() {
    const that = this;
    quill_ready(function() {
        that._initialize_quill();
        that._mark_ready();  // Called AFTER quill is ready
    });
}
```

### _transform_value(value) (OPTIONAL)

Transform buffered value for getter return. Used when value needs conversion:

```javascript
// Example: Checkbox converts to checked_value/unchecked_value
_transform_value(value) {
    const should_check = (value === this.checked_value || value === '1' || value === 1);
    return should_check ? this.checked_value : this.unchecked_value;
}
```

---

## Event Triggers

On user interaction, trigger BOTH events:

```javascript
this.$sid('input').on('input', function() {
    const value = that.val();
    that.trigger('input', value);  // User interaction only
    that.trigger('val', value);    // All changes
});
```

**`input` event**: Fires on user interaction ONLY
**`val` event**: Fires on ALL value changes (base class handles setter, you handle user interaction)

---

## Event Usage Patterns

### React to User Changes Only

```javascript
this.sid('country').on('input', (component, value) => {
    // Only fires when user selects, not when form populates
    this.reload_states(value);
});
```

### Get Current Value + All Future Changes

```javascript
// Because jqhtml triggers already-fired events on late .on() registration,
// this callback fires IMMEDIATELY with current value, then on every change
this.sid('amount').on('val', (component, value) => {
    this.update_total(value);
});
```

---

## Template Requirements

Templates render elements EMPTY. Forms populate via `val()`:

```jqhtml
<%-- CORRECT --%>
<Define:My_Input>
  <input type="text" $sid="input" class="form-control" />
</Define:My_Input>

<%-- WRONG - never set value in template --%>
<Define:My_Input>
  <input type="text" $sid="input" value="<%= this.data.value %>" />
</Define:My_Input>
```

**Note**: Input components have NO `on_load()`, so `this.data` is always `{}`.

**Using `Text_Input` directly**: `$max_length` is REQUIRED on every `Text_Input` instance - `Model.field_length('column')` for a database-driven limit, a numeric value for a custom limit, or `-1` for unlimited. A missing `$max_length` reports a console error rather than throwing, so it is easy to ship by accident.

---

## Value Transformation Example

For inputs that need to convert values (like Checkbox):

```javascript
class Checkbox_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();
        this.checked_value = this.args.checked_value || '1';
        this.unchecked_value = this.args.unchecked_value || '0';
    }

    _get_value() {
        return this.$sid('input').prop('checked')
            ? this.checked_value
            : this.unchecked_value;
    }

    _set_value(value) {
        const should_check = (value === this.checked_value ||
                              value === '1' || value === 1 || value === true);
        this.$sid('input').prop('checked', should_check);
    }

    _transform_value(value) {
        const should_check = (value === this.checked_value ||
                              value === '1' || value === 1 || value === true);
        return should_check ? this.checked_value : this.unchecked_value;
    }

    on_ready() {
        this._mark_ready();

        const that = this;
        this.$sid('input').on('change', function() {
            const value = that.val();
            that.trigger('input', value);
            that.trigger('val', value);
        });
    }
}
```

---

## Extending Other Inputs

When extending another input:

```javascript
class Select_Ajax_Input extends Select_Input {
    async on_load() {
        this.data.select_values = await Controller.get_options();
    }

    on_ready() {
        // Parent sets up TomSelect and calls _mark_ready()
        super.on_ready();
    }

    // _get_value() and _set_value() inherited from Select_Input
}
```

---

## Common Mistakes

| Mistake | Correct Approach |
|---------|-----------------|
| Implementing `val()` | Use `_get_value()` and `_set_value()` instead |
| Forgetting `_mark_ready()` | Must call in `on_ready()` to apply buffered values |
| Using `this.data` | Inputs have no `on_load()`, use `val()` only |
| Adding `class="Widget"` | Not needed, `.Form_Input_Abstract` is automatic |
| Not triggering both events | User interaction must trigger both 'input' and 'val' |
| Calling `_mark_ready()` too early | For async init, wait until actually ready |
| Setting value in template | Templates render empty, forms populate via `val()` |

---

## Built-in Reference Implementations

| Component | Notes |
|-----------|-------|
| `text/text_input.js` | Simple text input - minimal implementation |
| `select/select_input.js` | Dropdown with TomSelect |
| `checkbox/checkbox_input.js` | Uses `_transform_value()` |
| `wysiwyg/wysiwyg_input.js` | Async initialization |

## More Information

- `php artisan rsx:man form_input` - Complete documentation
- `rsx/theme/components/inputs/CLAUDE.md` - Implementation details
