---
name: form-components
description: "Composing a form in this application from its own field chrome and input roster - Form_Field / Form_Field_Abstract (label, $required asterisk, $help, the Slot:label rule), Rsx_Tabs / Rsx_Tab error badges, and the shipped inputs (Text_Input and its $max_length rule, Select_Input / Select_With_Description_Input / Select_Ajax_Input / Select_Country_Input / Select_State_Input, Checkbox_Input, Checkbox_Multiselect_Input, Tag_List_Input, Repeater_Simple, Pin_Input, Wysiwyg_Input, Profile_Photo_Input, Hidden_Input, Currency_Input, Phone_Text_Input). Use when laying out a form's fields, adding a new input to the roster, wiring a date or time field, choosing between two existing inputs, or hitting \"Text_Input with $name=... requires $max_length\", \"Form_Field_Abstract has no Form_Input_Abstract child\", or \"Form_Field_Abstract child input has no data-name attribute\"."
---

# Form chrome and the input roster

> **Living skill.** This skill ships with the template application and is yours. It describes
> the CURRENT state of `rsx/theme/components/forms/` and `rsx/theme/components/inputs/`; the
> directory files `rsx/theme/components/forms/CLAUDE.md` and
> `rsx/theme/components/inputs/CLAUDE.md` are its companions. When this feature changes,
> update this skill and those files in the same pass.

The form ENGINE is framework core and is not yours: `Rsx_Form`, `Form_Errors` and
`Form_Input_Abstract` live in `system/app/RSpade/Core/Forms/`. Skills
`rspade:form-engine` (submission, validation, loading, dirty parking) and
`rspade:form-input-contract` (`_get_value`/`_set_value`/`_mark_ready`/`_notify_input`) are
the contracts. **Everything in this skill is application code you may restyle, extend or
delete.**

| Layer | Lives in | Owner |
|---|---|---|
| Form | `system/app/RSpade/Core/Forms/` | framework |
| Field (chrome) | `rsx/theme/components/forms/` | this app |
| Inputs | `rsx/theme/components/inputs/` | this app |

## Form_Field - chrome around ONE input

`rsx/theme/components/forms/form_field.{jqhtml,js}` renders a label, an optional red
asterisk and optional help text around exactly one input. `Form_Field_Abstract`
(`form_field_abstract.{jqhtml,js}`) is the behaviour-only base: it finds the child
`.Form_Input_Abstract`, reads its `data-name`, and wires the label's `for` attribute.
Extend the abstract to build a differently-styled field; extend `Form_Field` to vary the
shipped one.

```jqhtml
<Form_Field $label="Email Address" $required=true $help="We never share it.">
    <Text_Input $name="email" $type="email" $max_length=255 />
</Form_Field>
```

Rules that are not negotiable:

- **`$name` goes on the INPUT, never on `Form_Field`.** The field READS the child's
  `data-name`; it never sets one. A field with no input child raises *"Form_Field_Abstract
  has no Form_Input_Abstract child"*; an input with no `$name` raises
  *"Form_Field_Abstract child input has no data-name attribute."*
- **`$required` is presentation only** - the asterisk that ANNOUNCES a server rule. It
  enforces nothing here or anywhere. Writing it obliges you to go write the server rule;
  nothing will tell you it is missing (`rspade:form-engine`).
- **The field renders NO error markup.** A failed submit is rendered by the form's one
  renderer (`Form_Utils`), which pins the message under the field it targets. A second
  error path in the field would be a second styling path for the same failure - if a
  field variant you write starts rendering errors, that is the bug.
- **Label channel**: plain text goes in `$label`; ANY markup, icon or nesting goes in
  `<Slot:label>`. HTML inside the `$label` string renders escaped, which is the defect.
  With `<Slot:label>` present the jqhtml parser forbids loose content beside it, so the
  input MUST move into `<Slot:body>`.

```jqhtml
<Form_Field>
    <Slot:label><i class="bi bi-star"></i> Rating</Slot:label>
    <Slot:body><Text_Input $name="rating" $max_length=3 /></Slot:body>
</Form_Field>
```

A field does **not** have to sit inside a form - a bare `Form_Field` is a legitimate way
to lay an input out outside a submission context.

## Rsx_Tabs / Rsx_Tab - a long form in tabs

`rsx_tabs.{jqhtml,js}` + `rsx_tab.{jqhtml,js}`. The container builds its nav from the
`Rsx_Tab` children that register with it, persists the active tab to the URL hash
(`Rsx.url_hash_set_single('tab', ...)`), and is **form-aware**: on a failed submit it
badges the tabs holding errored fields and switches to the first one, so a validation
message is never hidden behind an inactive tab.

```jqhtml
<Rsx_Form $controller="Frontend_Clients_Controller" $method="save" $data=this.data.form_data>
    <Form_Errors />
    <Rsx_Tabs>
        <Rsx_Tab $sid="basic" $label="Basic Information" $icon="bi-building">
            <Form_Field $label="Name"><Text_Input $name="name" $max_length=255 /></Form_Field>
        </Rsx_Tab>
        <Rsx_Tab $sid="address" $label="Address" $icon="bi-geo-alt">
            <Form_Field $label="Street"><Text_Input $name="street" $max_length=255 /></Form_Field>
        </Rsx_Tab>
    </Rsx_Tabs>
    <button type="submit" class="btn btn-primary">Save</button>
</Rsx_Form>
```

Place `<Form_Errors />` OUTSIDE the tab set - the summary must be visible whichever tab
is active.

**No shipped screen uses `Rsx_Tabs`/`Rsx_Tab` today.** They are the shape a long, sectioned
edit form is meant to take here; every form in `rsx/app/` and `rsx/portal/` is currently short
enough to be a flat field stack. (The neutral display tab strip on view pages is the separate
`Tab_Bar`/`Tab_Panels` pair - do not cross them.)

## How a form is composed here

- **A page form** is an add/edit action under `rsx/app/frontend/<feature>/edit/`, one
  action carrying both `@route`s. Worked example:
  `rsx/app/frontend/tasks/edit/Tasks_Edit_Action.{js,jqhtml}`; the tabbed variant is
  `rsx/app/frontend/clients/edit/`.
- **A modal form** is a body component whose template wraps an `<Rsx_Form>` and which
  usually needs no JS class at all - app skill `modals`. Worked example:
  `rsx/app/frontend/settings/api_keys/add_api_key_modal_form.jqhtml`.
- **Grouping** is `<Section $title="...">` (`rsx/theme/components/section/`) and the
  responsive grid; app skill `semantic-components`. Multi-column layouts put the grid
  around the `Form_Field`s.

## The shipped input roster

Every one extends `Form_Input_Abstract` and lives under `rsx/theme/components/inputs/`.

| Input | Directory | What it is |
|---|---|---|
| `Text_Input` | `text/` | the workhorse: text, `$type="email\|password\|number\|textarea\|date\|time\|datetime-local"`, `$rows` |
| `Currency_Input` | `text/` | `Text_Input` with currency formatting |
| `Phone_Text_Input` | `text/` | `Text_Input` with libphonenumber formatting; stores E.164 |
| `Select_Input` | `select/` | TomSelect-backed single select |
| `Select_With_Description_Input` | `select/` | select whose options carry a description line |
| `Select_Ajax_Input` | `select/` | `Select_Input` whose options arrive from an endpoint in `on_load()` |
| `Select_Country_Input` / `Select_State_Input` | `select/` | `Select_Ajax_Input` bound to `Rsx_Reference_Data_Controller` |
| `Checkbox_Input` | `checkbox/` | one boolean, with `$checked_value`/`$unchecked_value` |
| `Checkbox_Multiselect_Input` | `checkbox_multiselect_input/` | a checkbox list; the value is an array of ids |
| `Tag_List_Input` | `tag_list/` | a list of short strings as ONE value |
| `Repeater_Simple` | `repeater/` | a list of values built with any other input as the row editor |
| `Pin_Input` | `pin/` | a short numeric code shown as N single-character boxes |
| `Wysiwyg_Input` | `wysiwyg/` | Quill rich text; `val()` sanitizes through `safe_html()` |
| `Profile_Photo_Input` | `photo/` | thumbnail + upload, backed by the file-attachment flow |
| `Hidden_Input` | `hidden/` | a value carried through the form with no UI |

**`Text_Input` requires `$max_length`** and throws without it: pass
`Model.field_length('column')`, a literal number, or `-1` for "no limit". Subclasses
(`Currency_Input`, `Phone_Text_Input`) are exempt - the check fires only when
`Text_Input` is used directly.

**There is no date-picker component.** A date or time field is a `Text_Input` with a
native `$type`, and the browser supplies the picker; the string it produces is already
the `Rsx_Date`/`Rsx_Time` ISO format. `php artisan rsx:man datetime_inputs`.

**Rich text is XSS-sensitive.** `Wysiwyg_Input` sanitizes on the way out, but stored
content must still be rendered through `safe_html()` at every display site.

### Vendor libraries these inputs bring with them

Asset bundles co-located with the component, auto-discovered by every module bundle that
scans `rsx/theme`:

- `select/tom_select_bundle.php` - TomSelect from CDN, for the select family.
- `text/phone_libphonenumber_bundle.php` - `google-libphonenumber` as a global, the JS
  twin of the PHP `giggsey/libphonenumber-for-php` used by `Rsx\Lib\Formatters::phone()`,
  so both languages format identically.
- Quill is named explicitly as `Quill_Bundle` in `rsx/app/frontend/frontend_bundle.php`
  (CDN-only, no local files).

Deleting an input means deleting its bundle too - and an external resource is DECLARED,
never hand-injected (`rspade:external-resources`).

## Adding an input to the roster

1. Make a directory under `rsx/theme/components/inputs/<group>/` and name the files
   `<name>_input.{jqhtml,js}` (plus `.scss` if it needs styling of its own).
2. Extend `Form_Input_Abstract` - or an existing input when you are adding behaviour to
   one - and implement `_get_value()` / `_set_value()`, call `_mark_ready()` at the
   earliest moment a write would stick, and announce user edits with
   `_notify_input(value)`. The full contract, including why you never override `val()`:
   `rspade:form-input-contract`.
3. Render the element EMPTY - no `value` attribute; the form writes the value.
4. Write no client-side validation. That rule is the server's.
5. Style it in its own `.scss` under a single class matching the component name, with
   colours as `var(--bs-*)` tokens (app skill `theme`; `rspade:scss-rules`).
6. Add a row to the table above and to `rsx/theme/components/inputs/CLAUDE.md`.

Nothing needs registering: the manifest discovers the component and the module bundles
already scan `rsx/theme`.

## Troubleshooting

| Symptom | Cause |
|---|---|
| *"Text_Input with `$name`=... requires `$max_length`"* | Pass `Model.field_length('col')`, a number, or `-1` |
| *"Form_Field_Abstract has no Form_Input_Abstract child"* | The field wraps markup instead of an input component |
| *"child input has no data-name attribute"* | `$name` was put on `Form_Field` instead of the input |
| The label renders raw HTML as text | Markup in `$label`; use `<Slot:label>` + `<Slot:body>` |
| A validation message is invisible | It is on an inactive tab and `Rsx_Tabs` was bypassed, or `<Form_Errors />` is inside a tab |
| A field shows two error messages | A field variant rendered its own; delete it - `Form_Utils` owns error display |

Related: `rspade:form-engine`, `rspade:form-input-contract`, `rspade:jqhtml`, app skills
`modals`, `semantic-components`, `theme`. Contracts: `rsx:man form_conventions`,
`rsx:man form_input`, `rsx:man datetime_inputs`.
