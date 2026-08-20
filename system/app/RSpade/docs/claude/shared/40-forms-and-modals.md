<!-- single-source: never duplicate into another fragment. -->

## FORMS AND MODALS

A form is `<Rsx_Form $controller $method [$data]>` wrapping `<Form_Field>` (visible) and `<Hidden_Input>` (hidden), with the widgets (`Text_Input`, `Select_Input`, `Checkbox_Input`, ...) inside. **`$data` present = edit form, absent = add form**; values bind automatically by matching `$name` to keys.

**Always open an existing form as a reference before writing a new one** — binding is automatic rather than spelled out in markup, so copying a comparable form beats reasoning the wiring out.

**`$name` goes on the INPUT component, never on `Form_Field`, and you NEVER set `$value`** — the form sets it from `$data`. `$max_length` is REQUIRED on `Text_Input` (`Model.field_length('col')`, a number, or `-1`).

**The Action loads, the controller saves**: `on_create()` sets `this.data.form_data` defaults, `on_load()` fetches for edit mode, and the controller's `save()` (`#[Ajax_Endpoint]` + mandatory `#[Auth]`) validates and persists. `form_data` must be serializable — plain objects, never models. Forms read and write themselves through `vals()`, and a controller returning `response_form_error('...', ['title' => 'Required'])` gets its field errors placed automatically.

**Custom inputs extend `Form_Input_Abstract`**, render EMPTY and implement `val()` — the contract is timing indifference: if a caller needs `await component.ready()` to make `val()` work, the component is broken.

**Modals** are all async: `Modal.alert()`, `confirm()`, `prompt()`, `select()`, `error()`, `unclosable()`, `form()`. **Basic dialogs take POSITIONAL arguments, not an options object**, and they **overload**: 1 arg = body only, 2+ args = first is TITLE and second is BODY — easy to get backwards. `Modal.form({title, component, component_args, on_submit})` needs a component implementing `vals()`; `on_submit` returns `false` to keep the modal open or data to close with.

Skills: `rspade:forms`, `rspade:form-input`, `rspade:modals`, `rspade:ajax-error-handling`. Details: `rsx:man form_conventions`, `rsx:man modals`.
