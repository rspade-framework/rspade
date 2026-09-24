# rsx/lib/text_types — what kind of string each TEXT column holds

## WHAT IS HERE

| Path | What it is |
|---|---|
| `raw_text/` | `Raw_Text` (PHP: the encoding) + `raw_text.js` (which components print and edit it). A block of user-authored plain text. |
| `rich_text/` | `Rich_Text` + `rich_text.js`. Sanitized HTML from a WYSIWYG, filtered by `safe_html()` on write. |

Both extend the framework's `Rsx_Text_Abstract`. **The framework ships the abstract
and no concrete types at all** — which ones exist, and what each permits, is this
application's decision.

## THE PROBLEM THEY SOLVE

A TEXT column may hold plain text, or HTML, or a notation of the application's own
(`@@E(User,33)` for an entity reference). That fact is a property of the COLUMN, but the
value stops carrying it the moment it is read: `$project->description` used to be a
string, and every place that printed, edited, exported or indexed it had to independently
remember which kind it was and pick the matching escape, widget or filter.

The cost of misremembering was not a crash. It was either markup rendered as literal text,
or — the direction that matters — user input emitted unescaped.

Declaring the type on the model moves that knowledge to one place and lets the value carry
it:

```php
public static $text_types = [
    'description' => Rich_Text::class,
    'notes' => Raw_Text::class,
];
```

The column is now read as a value object, filtered by its type on every write, printed by
its type's component, reduced by `to_text()` for a CSV cell or a search index, and refused
by any editor that does not accept it. **A column with no declaration is unchanged** — an
ordinary string, exactly as before. Declaration is opt-in.

## HOW IT IS USED

**Declared on the model**, beside `$enums`. `Project_Model` and `Client_Model` are the
worked examples. Every `description` column in this application holds `Rich_Text`:
`Project_Model`, `Task_Model`, `User_Group_Model` and `Demo_Product_Model`. The three that
were converted from plain text were re-encoded first, by
`rsx/resource/migrations/2026_09_15_052838_convert_description_columns_to_rich_text.php` -
the worked example of the two-act rule below.

**Read and written like any other column.** An endpoint still writes
`$project->description = $params['description'];` — the value arrives already typed
(rehydrated at the Ajax boundary, or from a JSON-encoded envelope in an `/api/vN` string
param) and is filtered by the type on assignment. A BARE STRING is plain text: the type's
`escape_string()` converts it and `filter_set()` runs on the result, so a seed, an import or
a plain API param stores what was typed. Encoded content from server code is assigned as
`Rich_Text::from_untrusted($html)`. No endpoint in this application calls a sanitizer.

**The bare-string write is the one nothing checks.** Markup you build and assign as a string
is stored as visible tags, silently; prose you wrap in `<p>` yourself shows the `<p>`. So a
helper that composes content for a declared column returns `Rich_Text` (built once with
`from_untrusted()`), never a string, and prose is assigned bare.

**Two render targets, each written once.** `to_html()` is the STATIC rendition for a
server-generated document (an email, a PDF). A live page renders through the type's
`PRINTER` component instead — `Rich_Text_Display` and `Raw_Text_Display`, both under
`theme/components/view/`. They are different targets, not two implementations of one thing.

**Printing is just interpolation.** `<%= this.data.project.description %>` mounts the
type's printer: jqhtml routes an object at an interpolation site to the framework's
registered value printer, which delegates to the type. The template names no component and
chooses no escaping. `rsx:man jqhtml`, ADVANCED: VALUE PRINTERS.

**Editing need not name a widget either.** `<{Project_Model.editor_for('description')}
$name="description" />` asks the column's type which component edits it — which is what
makes "change the declaration and the editor follows" true rather than aspirational. That
asks the MODEL and not the value, because an add form has no value to ask.

**`to_text()` is server-only.** The server can reduce a value synchronously because it has
the database; the browser often cannot (resolving an entity tag to a name is a lookup), so
the JS class has no `to_text()` and `toString()` throws rather than yielding
`[object Object]`. **On the server, string coercion throws too.** A typed value used as a
string is a typed value used wrongly - `$copy->notes = $orig->notes . 'more'` would strip
the markup and store something plausible. Say what you mean: `to_text()` for a CSV cell,
`to_html()` for a document, `is_empty()` for validation.

**What an endpoint receives.** A declared field arrives in `$params` as a typeless
`Rsx_Text_Request_Value` that answers `is_empty()` and accepts assignment, and throws on
everything else. The client's claimed type is carried as an opaque string and never
resolved; the COLUMN decides the type at assignment and runs the filter there. To read the
content before storing it - the users tagged in a comment - name the type explicitly:
`Tagged_Text::from_request($params['body'])->tagged_user_ids()`. Assign-then-validate
(`$model->col = $value; if ($model->col->is_empty()) ...`) needs no explicit resolution.

## HOW TO CUSTOMIZE

- **Adapt `Rich_Text` rather than working around it.** The usual reason is the allow-list:
  `filter_set()` calls `safe_html()`, and an application permitting embedded media or its
  own classes changes that one method.
- **A new type needs TWO required methods**: `filter_set()`, the server sanitizer, and
  `escape_string()`, the plain-text conversion every bare string goes through - each
  required even as a passthrough, so "no filter" is always a written declaration with its
  reason (see `Raw_Text`). Everything else is a CONVENTION the type adopts only when it needs the
  capability, and throws by default: `to_text()` (a plain rendition, for a CSV cell or an
  index) and `to_html()` (a server-side markup rendition, for an email or export - rarely
  needed, since a page renders through the PRINTER component). Anything the application
  must READ out of the encoding is a dedicated method on the type.
- **`is_empty()` defaults to the raw form.** A type whose encoding WRAPS its content
  overrides it - `Rich_Text` does, because an emptied WYSIWYG stores `<p><br></p>`. On the browser side it needs a class of the
  same name declaring `PRINTER` and `EDITOR` and nothing else.
- **An editor component edits exactly one type**, declared with `static ACCEPTS`. That is
  what lets a mismatch be refused instead of silently stringified — see
  `theme/components/inputs/raw_text/` and `.../wysiwyg/`.
- **Changing a column's type is two acts**: a migration that re-encodes the existing rows,
  then the one-line change here. `Type::from_string($plain)` (`escape_string()` then `filter_set()`) is the re-encoding path.
- **Use `is_empty()`, never `=== ''`.** A value object is never identical to a string, so
  `=== ''` is permanently false and an empty body reads as non-empty. This is the single
  most likely mistake when adopting a type on an existing column.

## DIVERGENCES FROM FRAMEWORK DOCS

`rsx/lib/CLAUDE.md` states that a `lib` class is `public static`. Text types are the
exception: a text value is an INSTANCE, because each one is a distinct value. That is the
feature, not a deviation from the static-first rule — the rule is about avoiding classes
that exist only to hold functions.

## RELATED

`../CLAUDE.md` · `../../theme/components/inputs/CLAUDE.md` · `../../models/CLAUDE.md`
