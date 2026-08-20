# RSX External REST API Module

External, bearer-authenticated REST endpoints for this app's data. This is the
template app's API surface (`/api/vN/...`); the runtime, auth, validation, logging and
docs live in framework core (`App\RSpade\Core\Api`).

## What lives here

- `v1/contacts_api_controller.php` - contacts CRUD (`Contacts_Api_Controller`).
- `v1/clients_api_controller.php` - clients CRUD (`Clients_Api_Controller`).

Each new API version gets its own `vN/` directory. Every endpoint pattern MUST start
`/api/vN/` (N = one or more digits) - the manifest scan throws otherwise.

## Endpoint pattern

Controllers extend `Rsx_Api_Controller_Abstract`. Endpoints are static methods
`(Request $request, array $params = [])`, declared with `#[Api_Endpoint]` plus one
`#[Api_Param]` per accepted parameter (one attribute per line). Only GET and POST are
allowed. The dispatcher authenticates, validates + coerces the declared params, and
passes them as `$params`; an undeclared parameter is rejected 422.

```php
#[Api_Endpoint('/api/v1/contacts/:id', methods: ['GET'])]
#[Api_Param('id', type: 'int', required: true, description: 'Contact ID')]
public static function get(Request $request, array $params = [])
{
    $contact = Contact_Model::find($params['id']);
    if (!$contact) {
        return Rsx_Api::not_found('Contact not found');
    }
    return $contact;
}
```

`#[Api_Param]` args: `name`, `type` (`string|int|float|bool` only in v1), `required`,
`default`, `description`, `example`. `required: true` cannot combine with a `default`.
Every `:token` in the pattern needs a matching param. Do NOT use another class's
constant as a `default` - it forces that class to autoload during the manifest scan and
fails; use a literal with a comment.

## Response contract

Bare JSON with real HTTP status codes - never `response()->json()` directly, never a
`{success,...}` envelope. Return:

- a model or Eloquent collection -> 200, serialized via the redacting `toArray()`
  (enum `__label`/`__badge` and `__MODEL` included);
- a plain array -> 200 (nested models auto-serialize);
- `null` -> 204;
- an `Rsx_Api` helper: `created($data)` 201, `no_content()` 204, `not_found()` 404,
  `unauthorized()`/`forbidden()`, `validation_error(array $fields, ?string $msg)` 422,
  `error($code, $msg, $status, $fields)`.

Errors are `{"error":{"code","message","fields"?}}`.

## Site scoping

Both models are site-scoped. Authentication establishes a headless Session identity, so
the global site scope stamps `site_id` on create and filters every read automatically -
write NO manual `->where('site_id', ...)`. A cross-site id simply comes back null from
`find()` (return `Rsx_Api::not_found()`).

## Auth & testing

`Authorization: Bearer rsk_...` (mint keys in Settings > API Keys). External calls in
dev must use the `APP_URL` host or loopback (dev hostname guard). CLI test:

```
curl -H "Authorization: Bearer rsk_test_..." http://localhost/api/v1/contacts
```

## Docblocks

The first paragraph is the docs description. An optional `@api-response` tag followed by
an indented example-JSON block is shown on the docs page. Add one to every endpoint.

Full framework reference: `php artisan rsx:man external_api`.
