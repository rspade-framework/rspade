---
name: rsx-stdlib
description: The RSpade shared utility library in both languages - JS global functions (type checks, html/safe_html, foreach/clone/coalesce), async helpers (sleep, debounce, rwlock), hash/deep_equal, browser helpers, Rsx_Storage, URL hash state, PHP helpers (response_*, array_*, bytes_to_human, random_hash, rsxrealpath), and the Manifest reflection API. Use before writing any utility by hand - debouncing, deep comparison, HTML escaping, dot-path array access, byte/duration formatting, class-hierarchy lookups - and when you need the exact signature of one of these functions.
---

# RSpade standard library

**Always use these built-in functions** — never reimplement debounce, type checking, string escaping, or similar utilities. Consistent use of shared tooling is what keeps this codebase maintainable and predictable.

Before hand-rolling anything, search first:

| Where | What |
|---|---|
| `php artisan rsx:man helpers` | **PHP** globals (`helpers.php`): `response_*`, `array_*`, string/HTML, filesystem/paths, `exec_safe`, formatting, `random_hash`, debug and `shouldnt_happen`. |
| `php artisan rsx:man js_functions` | **JS** globals (`functions.js`, `async.js`, `hash.js`, `error.js`, `browser.js`): type checks and conversion, `html`/`safe_html`, collections, `sleep`/`debounce`/`rwlock`, `hash`/`deep_equal`, browser helpers. |
| `Manifest.php` (below) | reflection - **check for an existing manifest function before hand-rolling any of it.** |

Those two man pages carry the full rosters with exact signatures. The judgment worth repeating here:

- **`html()` vs `safe_html()` is a security decision, not a style one.** Escape by default; sanitize only content that is SUPPOSED to be markup (WYSIWYG output). Same rule in PHP: `htmlbr()` for text, `safe_html()` for markup.
- **`debounce(fn, delay)` - always use it, never a hand-rolled timer.** The behavioural gotcha: the delay timer starts AFTER your callback completes, so a slow callback cannot stack up behind itself. Decorator form `@debounce(250)` (with the `/** @decorator */` comment).
- **`clone()` is SHALLOW** and **`empty(0)` is true** - these globals follow PHP semantics, not JavaScript ones.
- **`hash()` ignores the `$` key by default**, because jqhtml component data carries a jQuery reference that would otherwise hash the DOM. Supplying your own `ignored_keys` REPLACES that default.
- **`deep_equal(a, b)`** is the right "did this actually change" test before a repaint.
- **`rsxrealpath()`, not `realpath()`** - `/rsx` and `system/.env` are symlinks whose logical path is the meaningful one.
- **`random_hash()`** for tokens and keys; never `rand()`/`uniqid()`.
- **`exec_safe()`** is the one subprocess wrapper (explicit bash, merged stderr, real exit status) - but an artisan subprocess goes through `Rsx_Artisan`.
- **`bytes_to_human()`** renders an ACTUAL size. The configured upload ceiling has its own label function.
- JS `rwlock`/`rwlock_read` are IN-PAGE locks; server/cluster locking is `RsxLocks`, a different system.

---

## Rsx_Storage - scoped browser storage

Scoped sessionStorage/localStorage with automatic fallback. Keys are auto-scoped by session/user/site/build, so one user's cached view never leaks into another's and a new build invalidates cleanly.

```javascript
Rsx_Storage.session_set(key, value);   // cleared on tab close
Rsx_Storage.session_get(key);
Rsx_Storage.session_remove(key);

Rsx_Storage.local_set(key, value);     // persists across sessions
Rsx_Storage.local_get(key);
Rsx_Storage.local_remove(key);
```

**Non-critical data only** (cached data, UI state). **Graceful degradation in private browsing** - storage may silently be unavailable, so nothing correctness-bearing may live here. Details: `rsx:man storage`.

---

## URL hash state

Persist UI-only view state (filters, tab selection, dropdown values) to the URL hash so a refresh preserves the view. Format `#key=value&key2=value2`, URL-encoded. Uses `history.replaceState`, so no history entry is added per change.

| Function | Purpose |
|---|---|
| `Rsx.url_hash_get(key)` | single value or `null` |
| `Rsx.url_hash_get_all()` | all params as an object |
| `Rsx.url_hash_set_single(key, value)` | write one key (`null`/`''` removes it) |
| `Rsx.url_hash_set({k1: v1, k2: null})` | bulk write/remove |

**Convention — only persist deviations from defaults** so the URL stays clean:

```javascript
// Write: omit when the value matches the default
Rsx.url_hash_set_single('status', val !== DEFAULT_STATUS ? val : null);

// Read: fall back to the default when absent
this.args.status = Rsx.url_hash_get('status') || DEFAULT_STATUS;
```

**Use for**: filter/tab/view state that should survive refresh.
**Don't use for**: data scoping (use `$extra_params`), default values (apply on init, don't write), required state (use route params).

Details: `rsx:man url_hash`.

---

## Manifest reflection (PHP)

The manifest is a JIT index of all application code, so reflection questions are answered from an index rather than by loading classes. **Always check `Manifest.php` first for any reflection operation** - a function usually already exists.

### PHP class queries

| Function | Returns |
|---|---|
| `Manifest::php_is_subclass_of(string $sub, string $super): bool` | traverses the FULL chain, not just the direct parent |
| `Manifest::php_get_extending(string $parentclass): array` | concrete classes extending that parent |
| `Manifest::php_get_subclasses_of(string $class, bool $concrete_only = true): array` | direct subclasses |
| `Manifest::php_get_lineage(string $class): array` | the full ancestry chain |
| `Manifest::php_is_abstract(string $class): bool` | |
| `Manifest::php_find_class(string $name): string` | the class's file path |
| `Manifest::is_php_model_class(string $name): bool` | |

### JavaScript class queries

`Manifest::js_is_subclass_of()` · `js_get_extending()` · `js_get_subclasses_of()` · `js_get_lineage()` · `js_find_class()` - same intent as the PHP twins.

Two asymmetries worth knowing (verified against `Core/Manifest/Manifest.php`): `js_get_subclasses_of(string $class)` takes **no `$concrete_only` argument**, and there is **no `js_is_abstract()`**.

### File lookup

`Manifest::get_path_by_filename(string $filename): string` - quick lookup by filename; the filename must be unique within `/rsx/`.
