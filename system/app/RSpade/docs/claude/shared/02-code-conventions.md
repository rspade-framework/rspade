<!-- single-source: never duplicate into another fragment. The UI philosophy and z-index bands live in 32. -->

## CODE CONVENTIONS

**Naming — enforced by `rsx:check`**: PHP methods/variables `underscore_case`; constants `UPPERCASE_WITH_UNDERSCORES`; RSX application classes `Like_This_With_Underscores`; JS classes `Like_This`; files `lowercase_with_underscores`; database tables `lowercase_plural`. Input components follow `{Supertype}_{Variant}_{Supertype}` (`Select_Country_Input`).

**Files sharing a prefix are one related set** (`frontend_calendar_event.php` / `.js` / `.jqhtml` / `.scss`) — when renaming, maintain the grouping across ALL of them, and **never create same-name different-case files**. Exceptions are declared with `@FILENAME-CONVENTION-EXCEPTION`.

**No field aliasing** — field names are identical across ALL layers, database -> PHP -> JSON -> JavaScript. Return `['type_id__label' => ...]`, never `['type_label' => ...]`. One string everywhere, so **grep finds all usages**.

**Static-first**: classes are namespacing tools. Use static unless instances are genuinely needed (models, resources, service connectors); **avoid dependency injection**.

**NEVER define attribute classes** — attributes work via reflection, and the linter removing their `use` statements is CORRECT. **NEVER manually update namespaces** — the manifest regenerates them.

Attribute markers (metadata only): `#[Instantiatable]` whitelists abstract-class children for instantiation; `#[Replaceable]` on a PARENT method lets overriders skip the `parent::` call; `#[Sealed]` on a PROPERTY forbids redeclaration by any descendant. **Parent-call chaining (`PHP-PARENT-CHAIN-01`)**: an override MUST call `parent::<same-method>()` unless the nearest declaring ancestor is abstract, `#[Replaceable]`, or a vendor class. Both are manifest-build FATALs.

**JS non-class files** hold only functions, `const` with STATIC values, and decorators — `const RANDOM = Math.random()` and `let variable = 42` are forbidden. **JS decorators require the `/** @decorator */` marker** on the definition.

**`php artisan rsx:check`** enforces the above plus the `that = this` pattern (`JS-THIS-01`: jQuery callbacks alias `const $element = $(this)`, anonymous functions `const that = this`, static methods never use naked `this`). **Every finding carries its own remediation text — trust it as authoritative.**

Details: `rsx:man coding_standards`; skill `rspade:js-decorators`.
