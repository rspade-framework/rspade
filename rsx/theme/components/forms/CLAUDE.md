# rsx/theme/components/forms — field chrome around one input

## WHAT IS HERE

- `form_field_abstract.{jqhtml,js}` — the base field wrapper: it holds exactly ONE
  `Form_Input_Abstract` child, reads that child's `data-name` in `on_ready()` for the
  label/accessibility wiring, and renders no visual formatting of its own. Extend it to
  build a differently-shaped field.
- `form_field.{jqhtml,js}` — the standard field: `$label` (or `<Slot:label>`), the
  `$required` asterisk, `$help` text, and the input as loose content. All behaviour is
  inherited; the class exists for the template.
- `rsx_tabs.{jqhtml,js}` — a form-aware tab container: it builds its strip from the
  `Rsx_Tab` children that register with it, persists the active tab to the URL hash, and
  puts an error-count badge on a tab whose fields failed, switching to the first such tab.
- `rsx_tab.{jqhtml,js}` — one pane inside `Rsx_Tabs`: `$id`, `$label`, `$icon`. It
  discovers its child `Form_Field`s and reports their error count to the container.

There is no SCSS in this directory — the field look rides Bootstrap's form classes.

## HOW IT IS USED

`<Form_Field>` wraps a single input inside an `<Rsx_Form>`; `$name` goes on the INPUT,
never on the field, and the field never sets `data-name` itself. Live examples:
`rsx/app/frontend/clients/edit/Clients_Edit_Action.jqhtml` and
`rsx/app/frontend/settings/profile_edit/settings_profile_edit_action.jqhtml`.

**`$required` renders a red asterisk and enforces nothing** — it announces a rule the
server endpoint applies. There is no client-side required/format/length checking here.

**A field renders no error markup.** A failed submit is painted by the form's single
error renderer, which pins each message under the field it targets; a second error path
in the field would be a second styling path for one failure. A field also does not need
to be inside a form — a bare `<Form_Field>` is a legitimate way to lay an input out.

`Rsx_Tabs`/`Rsx_Tab` are the FORM tab pair (validation-aware). The neutral display tab
strip is `../tabs/` (`Tab_Bar`/`Tab_Panels`) — do not cross them. Neither `Rsx_Tabs` nor
`Rsx_Tab` has a consumer in `rsx/app` or `rsx/portal` today; they are the shipped shape
for a long, sectioned edit form.

The form ENGINE — `Rsx_Form`, `Form_Errors`, `Form_Input_Abstract`, `Form_Utils` — is
framework core in `system/`, not here: skills `rspade:form-engine` and
`rspade:form-input-contract`. Composition depth is the app skill `form-components`.

## HOW TO CUSTOMIZE

- **Restyle the standard field**: edit `form_field.jqhtml` (and add a `form_field.scss`
  wrapped in a single `.Form_Field` class, BEM children prefixed `Form_Field__`).
- **A field VARIANT** (an inline label-left field, a compact grid field) is a NEW
  component that `extends="Form_Field_Abstract"` with its own template and SCSS — never a
  page-scoped override of `Form_Field`, and never a second copy of the name-discovery
  logic, which lives in the abstract.
- **Never change the name flow**: the input stamps `data-name`, the field reads it. A
  field that sets `data-name` itself breaks error targeting for every form.
- `Rsx_Tab` writes `$id` onto its own element and `Rsx_Tabs` reads the hash under that
  id — renaming a tab id changes bookmarked URLs.
- Both wrappers may be deleted outright if the fork lays fields out its own way; the
  engine does not require them.

## RELATED

App skill `form-components` · app skill `theme` · `../inputs/CLAUDE.md` · `../tabs/CLAUDE.md` ·
skills `rspade:form-engine`, `rspade:form-input-contract`, `rspade:jqhtml` ·
`rsx:man form_conventions`, `rsx:man form_input`
