---
name: jquery-extensions
description: RSX jQuery extensions including the click() override that auto-prevents default, .click_async() button busy-states, existence and viewport checks, component-aware traversal, form validation helpers, scrolling, and the width_group family. Use when working with click handlers, wiring a button to a server-side action, checking element existence or visibility, finding sibling components, syncing widths across elements, understanding why links don't navigate, or hitting the "$.ajax() is blocked" throw.
---

# RSX jQuery Extensions

## Critical: Click Override

**RSX overrides jQuery `.click()` to automatically call `e.preventDefault()`**

This is the most important thing to know. All click handlers prevent default behavior automatically:

```javascript
// RSX - preventDefault is automatic
$('.btn').click(function(e) {
    do_something();  // Link won't navigate, form won't submit
});

// Vanilla jQuery - must remember to call it
$('.btn').click(function(e) {
    e.preventDefault();  // Easy to forget!
    do_something();
});
```

**Why**: `preventDefault` is correct 95% of the time. Making it automatic eliminates a common source of bugs.

---

## When You Need Native Behavior

Use `.click_allow_default()` for the rare cases where you want native browser behavior:

```javascript
// Analytics tracking before navigation
$('a.external').click_allow_default(function(e) {
    analytics.track('external_link');
    // Navigation happens after handler
});

// Conditional preventDefault
$('button[type=submit]').click_allow_default(function(e) {
    if (!validate_form()) {
        e.preventDefault();  // Only prevent if invalid
    }
});
```

**Valid use cases for `.click_allow_default()`**:
- Analytics tracking before navigation
- Conditional form submission
- Progressive enhancement fallbacks

**Invalid use cases** (use standard `.click()` instead):
- Opening modals
- Triggering Ajax actions
- Any case where you don't want navigation

---

## Buttons That Trigger Server-Side Actions: `.click_async()`

```javascript
$('.save-btn').click_async(async function(e) {
    await My_Controller.save(form.vals());
    // Button shows a loader until this resolves, then restores
});
```

On click the element enters a busy state via the core `Button_Utils`: its rendered size is locked, its content is swapped for a CSS-animated loader, and interaction is blocked (`btn--submitting` sets `pointer-events: none` - deliberately NOT the `disabled` attribute). The busy state clears when the returned promise settles.

Contract:
- Throws immediately if the handler is not a function.
- Auto-`preventDefault`, like `.click()`. Returns `this` (chainable).
- Re-entrant clicks are ignored while a run is in flight (covers `.trigger('click')` and double-taps).
- On settle the busy state clears only if the element is still in the DOM - a handler that navigates the SPA away leaves the detached node untouched, no error.
- The handler's rejection is NOT caught: it escapes as an unhandled rejection and reaches the global handler (console + flash alert), exactly like any other uncaught async error.

**RECOMMENDED PATTERN — buttons that trigger server-side actions**: bind with `.click_async()` whenever a click fires an Ajax action and the screen the button lives on stays visible (send, sync, toggle, kick a task) — the user gets immediate visual feedback instead of a dead button during the round-trip. Not needed where chrome already provides it (`Rsx_Form` submits, `Modal.form()` buttons) or when the action immediately navigates away.

The loader is themeable via CSS custom properties (`rsx:man jquery`). `Button_Utils.set/clear/is_submitting($el)` is the underlying core API for the rare case you need to drive the state manually.

---

## Existence Checks

```javascript
// RSX - cleaner syntax
if ($('.element').exists()) {
    // Element is in DOM
}

// Vanilla jQuery equivalent
if ($('.element').length > 0) { ... }
```

---

## Visibility and State

```javascript
// Is element visible (not display:none)?
if ($('.modal').is_visible()) {
    $('.modal').fadeOut();
}

// Is element attached to DOM?
if ($('.dynamic').is_in_dom()) {
    // Element is live in the page
}

// Is element in viewport?
if ($('.lazy-image').is_in_viewport()) {
    load_image($(this));
}
```

---

## Component-Aware Traversal

### shallowFind(selector)

Finds child elements without descending into nested components of the same type:

```javascript
// Only finds direct Form_Field children, not fields in nested sub-forms
this.$.shallowFind('.Form_Field').each(function() {
    // Process only this form's fields
});
```

Example DOM:
```
Component_A
└── div
    └── Widget (found)
        └── span
            └── Widget (not found - has Widget parent)
```

### closest_sibling(selector)

Searches for elements within progressively higher ancestor containers. Useful for component-to-component communication:

```javascript
// Country selector finding its related state selector
this.tom_select.on('change', () => {
    const state_input = this.$.closest_sibling('.State_Select_Input');
    if (state_input.exists()) {
        state_input.component().set_country_code(this.val());
    }
});
```

Algorithm:
1. Get parent, search within it
2. If not found, move to parent's parent
3. Repeat until found or reaching `<body>`

---

## Form Validation

```javascript
// Check if form passes HTML5 validation
if ($('form').checkValidity()) {
    submit_form();
}

// Show browser's native validation UI
if (!$('form').reportValidity()) {
    return;  // Browser shows validation errors
}

// Programmatically submit (triggers validation)
$('form').requestSubmit();
```

---

## Other Helpers

```javascript
// Get lowercase tag name
if ($element.tagname() === 'a') {
    // It's a link
}

// Check if link is external
if ($('a').is_external()) {
    $(this).attr('target', '_blank');
}

// Scroll the page UP to bring an element into view - only acts if the
// element is above the viewport. speed is the animation duration in ms.
$('.error-field').scroll_up_to(300);
```

---

## Width Synchronization

Make several elements share a min-width, set by the widest member of the group:

```javascript
$('.Toolbar__btn').width_group('toolbar-buttons');

$.width_group_recalculate('toolbar-buttons');  // manual recalc after content change
$.width_group_recalculate();                   // recalc every group
$.width_group_destroy('toolbar-buttons');      // stop tracking
```

Elements are tracked individually by DOM reference, not by selector, so the same group name can be used from several calls to add more members. Calculation runs immediately and on window resize (debounced); elements removed from the DOM are pruned automatically.

Details: `php artisan rsx:man width_group` (this family is NOT in `man/jquery.txt`).

---

## BLOCKED: `$.ajax()`

**`$.ajax()` is overridden to throw** — use `Controller.method()` with `#[Ajax_Endpoint]` instead. The same applies to `$.get`/`$.post` habits: there is exactly one way to call the server from RSX, and it is the auto-mapped controller method.

---

## RSX vs Vanilla jQuery Comparison

| Operation | Vanilla jQuery | RSX |
|-----------|---------------|-----|
| Click with preventDefault | `e.preventDefault()` required | Automatic |
| Existence check | `.length > 0` | `.exists()` |
| Form validation | `$('form')[0].checkValidity()` | `$('form').checkValidity()` |
| Native click behavior | `.click()` | `.click_allow_default()` |

---

## Troubleshooting

**Problem**: Links not navigating when they should
**Solution**: Use `.click_allow_default()` instead of `.click()`

**Problem**: Form submitting unexpectedly
**Solution**: This shouldn't happen - `.click()` prevents submission. If using `.click_allow_default()`, add explicit `e.preventDefault()`

**Problem**: Want to use `.on('click')` to avoid preventDefault
**Solution**: Don't - it defeats the framework's safety. Use `.click_allow_default()` to make intent explicit

## More Information

Details: `php artisan rsx:man jquery`
