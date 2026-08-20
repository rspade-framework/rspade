<!-- single-source: never duplicate into another fragment. -->

## STANDARD LIBRARY & TIME

RSpade ships a complete shared utility library in both languages. **Always use these built-ins — never reimplement debouncing, type checking, deep comparison, HTML escaping, dot-path array access or byte/duration formatting.** Names below are trigger vocabulary; signatures live in skill `rspade:rsx-stdlib`.

- **JS**: `is_numeric` `is_string` `is_array` `is_object` `is_email` `isset` `empty`, `int()` `float()` `str()`; `html()` (escape) `safe_html()` (sanitize) `nl2br` `htmlbr` `urlencode` `json_encode/decode` `linkify_text/html`; `foreach()` `count()` `clone()` `coalesce()`; `sleep()`, **`debounce(fn, delay)` — always use this**, `rwlock`/`rwlock_read`; `hash()` `deep_equal()`.
- **PHP**: `response_error()` / `response_form_error()` / `response_unauthorized()` / `response_not_found()` / `response_auth_required()` / `response_fatal_error()`; `array_only` `array_except` `array_get`/`array_set` `array_first`/`array_last` `array_merge_deep`; `bytes_to_human()` `duration_to_human()` `random_hash()`; `ensure_directory()` `relative_path()` `rsxrealpath()`.
- **Manifest reflection** (`Manifest::php_is_subclass_of()`, `php_get_extending()`, `php_find_class()`, JS twins) — **check for an existing manifest function before hand-rolling reflection.**
- **Browser state**: `Rsx_Storage.session_*` / `local_*` (auto-scoped, graceful fallback — **non-critical data only**), `Rsx.url_hash_*` for UI-only view state.

**Date & time — two classes, strict separation**: `Rsx_Time` (moments, timezone-aware) and `Rsx_Date` (calendar dates, no timezone), identical API in PHP and JS, and **functions THROW when the wrong type is passed**. Everything is **ISO strings, never Carbon** — `"2025-12-24"` / `"2025-12-24T15:30:00-06:00"`, the same in PHP, JS, JSON and queries.

**MODEL ATTRIBUTES ARE ALREADY STRINGS.** `$model->created_at` is an ISO string, NOT a Carbon object — **never call `->format()` or `->toIso8601String()` on one**; pass it straight to `Rsx_Time::format_datetime(...)`. The right cast is applied automatically, and **defining `$casts` with `'date'`/`'datetime'`/`'timestamp'` is blocked by `rsx:check`** — and a rendered datetime always goes through an `Rsx_Time`/`Rsx_Date` formatter, never raw field interpolation (`JQHTML-DATETIME-01`). Timezone resolution is user preference (settable: `Rsx_Time::set_user_timezone` / the framework `Rsx_Timezone_Controller` endpoints; auto-set from the browser zone at boot when `login_users.timezone_auto` is on) -> site default -> config default; client time syncs from the server automatically.

Skills: `rspade:rsx-stdlib`, `rspade:date-time`. Details: `rsx:man helpers` (PHP), `rsx:man js_functions` (JS), `rsx:man time`, `rsx:man storage`.
