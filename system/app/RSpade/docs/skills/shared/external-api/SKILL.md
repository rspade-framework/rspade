---
name: external-api
description: Building externally-consumable REST endpoints with #[Api_Endpoint] and #[Api_Param] on Rsx_Api_Controller_Abstract - route rules, Bearer key auth and the headless session, param validation, response contract, versioning, and the /apidocs tester. Use when exposing an API to an external consumer or integration, adding a new /api/vN/ route or version, minting API keys, or diagnosing a 401 before route match, a 422 on an undeclared param, or a manifest scan that dies on an attribute argument.
---

# External API

Externally-consumable REST endpoints. **Distinct from `#[Ajax_Endpoint]`, which is browser-only** - Ajax endpoints assume a cookie session, a CSRF token, and the JS auto-mapping. An external API endpoint assumes none of that: a Bearer key, a URL, plain JSON.

## Building one

```php
class Contacts_Api_Controller extends Rsx_Api_Controller_Abstract {

    /** List contacts. @api-response {"items":[...],"meta":{...}} */
    #[Api_Endpoint('/api/v1/contacts', methods: ['GET'])]
    #[Auth('is_logged_in')]   // MANDATORY (staff realm - the bearer key IS a staff identity)
    #[Api_Param('page', type: 'int', required: false, default: 1)]
    #[Api_Param('search', type: 'string', required: false)]
    public static function list(Request $request, array $params = []) {
        return ['items' => Contact_Model::paginate(...), 'meta' => [...]];  // 200 bare JSON
    }
}
```

- Extend `Rsx_Api_Controller_Abstract`; methods are `public static (Request $request, array $params = [])`.
- `#[Api_Param]` is repeatable, one per accepted parameter.
- The docblock's `@api-response` sample is what `/apidocs` shows as the response shape.
- `#[Auth]` is mandatory here as on every dispatchable surface.

## Route rules (scan-enforced)

- Path MUST start `/api/vN/` (N = digits).
- **GET/POST only.**
- Every `:token` in the path needs a matching `#[Api_Param]`.
- No `#[Route]`/`#[SPA]`/`#[Ajax_Endpoint]`/`#[FPC]` on the same method.
- `/api/vN` is reserved against `#[Route]`/SPA - a normal route may not claim it.

Violations are a loud manifest-scan `RuntimeException`, not a runtime surprise.

## GOTCHA (LLM landmine)

**NEVER put a class constant in an attribute argument** (`#[Api_Param('x', default: Model::FOO)]`) — reflection resolves it before the autoloader is ready and the scan dies. Literals only.

The failure looks like a broken manifest build with a class-not-found deep in the scanner, and the attribute line rarely appears in the trace. If a scan dies right after you added an endpoint, look here first.

## Response contract

| You return | Client gets |
|---|---|
| a model or Collection | redacting `toArray()` - `neverExport` fields stripped, enum `__label` + `__MODEL` included |
| a plain array | 200 with that JSON |
| `null` | 204 No Content |
| `Rsx_Api::created($data)` | 201 |
| `Rsx_Api::no_content()` | 204 |
| `Rsx_Api::not_found()` / `unauthorized()` / `forbidden()` | 404 / 401 / 403 |
| `Rsx_Api::validation_error($fields)` | 422 |
| `Rsx_Api::error(...)` | the error you name |
| a scalar | **`shouldnt_happen`** - a bare scalar is never a valid API response |

Error shape is always `{"error":{"code","message","fields"?}}`.

## Authentication

`Authorization: Bearer rsk_...` ONLY -> a cookie-less headless `Session` identity (`Session::is_logged_in()`/`get_user()`/`get_user_id()`/`get_site_id()` all work; NO cookie, NO `_sessions` row, NO Set-Cookie; `get_session_id()==0`). Site scope applies automatically — **never hand-write `where('site_id')`**.

Keys are minted and revoked at **Settings > API Keys** (the plaintext key is shown once). Missing header -> 401, bad key -> 401, **both before route match** - so a bad key cannot be used to probe which paths exist.

`_api_keys.user_role_id` + `scopes` are RESERVED, NOT enforced. Do not build authorization on them; use `#[Auth]` and record-level checks like everywhere else.

## Param validation

Input precedence: **route params > GET query > JSON/form body.**

- 422 per-field on a missing required param, a bad type, or **any undeclared param**.
- 400 on an unparseable JSON body.

The undeclared-param rejection is deliberate: a client sending `?limt=50` gets told, instead of silently receiving unfiltered data.

## Versioning

URL-only, **exact-match at runtime** - there is no fallthrough from `/api/v2/x` to a v1 handler. Ship a new version as a new path.

The docs GROUP by (verb, path with the `vN` stripped) and show the newest version <= the one selected, so `/apidocs/v1` shows a v1 endpoint even when v2 exists elsewhere.

## Docs and tester

- Login-gated `/apidocs` (+ `/apidocs/vN`): per-endpoint cards, generated curl/php/js/python/powershell samples, and a live tester (paste a key, or mint a 1-hour tester key).
- LLM-readable catalog at `/apidocs/catalog` (login-gated JSON; **NOT `.json` — AssetHandler claims that path**).

## Interactions

Dev calls must use the `APP_URL` host or loopback; CSRF N/A (no cookie session); FPC never applies; `Main_Abstract::pre_dispatch` is skipped for API (Portal precedent). `_api_keys.user_role_id` + `scopes` are RESERVED, NOT enforced.

## Config

`rsx.api.log_retention_days` (default 30) - every request logs to `_api_request_log`, pruned by a daily cleanup task.

## Full reference

`php artisan rsx:man external_api`.
