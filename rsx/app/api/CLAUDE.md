# rsx/app/api — the external REST surface

External, bearer-authenticated REST endpoints for this app's data. This is the
template app's API surface (`/api/vN/...`); the runtime, auth, validation, logging and
docs live in framework core (`App\RSpade\Core\Api`).

## WHAT IS HERE

- `v1/contacts_api_controller.php` - contacts CRUD (`Contacts_Api_Controller`).
- `v1/clients_api_controller.php` - clients CRUD + document attachments (`Clients_Api_Controller`).
- `v1/tasks_api_controller.php` - task reads + attachments (`Tasks_Api_Controller`).

Each new API version gets its own `vN/` directory. Every endpoint pattern MUST start
`/api/vN/` (N = one or more digits) - the manifest scan throws otherwise.

## HOW TO CUSTOMIZE

- **Add an endpoint**: a static method on an existing `vN/` controller with
  `#[Api_Endpoint]` and one `#[Api_Param]` per accepted parameter. Add the `@api-response`
  docblock tag — the `/apidocs` console renders it, and an endpoint nobody can read is an
  endpoint nobody integrates with.
- **Add a version**: a new `vN/` directory. Never change the shape of an existing `vN`
  response — an external consumer cannot be redeployed with you.
- **Narrow what a key may reach** with scopes on the key rather than a gate here. A scope is
  a bare path pattern (`/api/v1/clients/#/view`), always a grant, and only ever subtracts
  from the holder's live permissions.
- **GET is for reads, POST is for writes.** Anything that changes application data goes on
  POST; incidental bookkeeping a GET does (a log row, a last-used stamp, a counter) is fine.
  `API-GET-PURE-01` fails the build on a mutating GET handler, and a key minted **read-only**
  (`_api_keys.read_only`, the Settings > API Keys checkbox, `--read-only`) is refused
  **403 `read_only_key`** on every non-GET request — two halves of one guarantee, and the
  flag is only worth anything while every GET here really is a read.
- **Delete an endpoint** only after checking `/apidocs` and any minted key whose scope names
  its path; a scope pointing at a removed route silently grants nothing.

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

`#[Api_Param]` args: `name`, `type` (`string|int|float|bool`, plus `file` for a
multipart upload part - array/json is still backlogged), `required`, `default`,
`description`, `example`. `required: true` cannot combine with a `default`. A `file` param
is POST-only, can never be a `:token`, and can never carry a default (all three are
manifest-scan failures).
Every `:token` in the pattern needs a matching param. Do NOT use another class's
constant as a `default` - it forces that class to autoload during the manifest scan and
fails; use a literal with a comment.

## File attachments

**The framework owns the bytes; this app owns "attach".** `POST /api/v1/files` (framework)
ingests a file and returns an unclaimed `key`; an endpoint HERE claims that key onto a
record, because which record, which category and who may do it are application policy the
framework cannot answer for you.

The pattern, three endpoints per record type - see `Clients_Api_Controller` and
`Tasks_Api_Controller`, which implement it identically:

| Endpoint | Does |
|---|---|
| `GET /api/v1/{thing}/:id/attachments` | `foreach ($record->get_attachments(CATEGORY))` |
| `POST /api/v1/{thing}/:id/attachments/attach` | `find_by_key()` + `can_user_assign_this_file()` + `add_to()` |
| `POST /api/v1/{thing}/:id/attachments/:attachment_id/delete` | `find_attachment()` then `delete()` |

Three things that are easy to get wrong:

- **`can_user_assign_this_file()` is STRUCTURAL, not a permission check.** It proves the
  file is still unclaimed and is in this tenant - nothing about WHO. Authorizing the claim
  is your endpoint's job (here, the class `#[Auth]` gate plus the site scope that found the
  record). It works fine under a Bearer identity: a key is a staff session with a real site.
- **Removing needs `$record->find_attachment($id_or_key, $category)`.** It returns null
  unless the attachment belongs to THIS record in THIS category. Resolving with a bare
  `File_Attachment_Model::find()` would let any attachment id in the site be deleted through
  any record's URL.
- **`add_to()` for many files, `attach_to()` for one.** `attach_to()` REPLACES whatever is
  already in the category.

`get_attachments()` returns an `Rsx_Result_Set`, not a Collection - iterate it, don't
`->map()` it. Clients attach into `Client_Model::DOCUMENTS_CATEGORY`, the same category the
staff Documents tab reads, so a file uploaded over the API appears in the UI. Deleting a
client document goes through `Client_Model::remove_document()`, which revokes its portal
shares before soft-deleting.

Downloads need no app endpoint: the URLs in the payload (`/_download`, `/_inline`,
`/_thumbnail/*`, `/_preview/pdf`) accept the same `Authorization: Bearer` key.

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

`Authorization: Bearer rsx_...` (mint keys in Settings > API Keys, or from the CLI).
External calls in dev must use the `APP_URL` host or loopback (dev hostname guard).
CLI test - mint a self-expiring key and call the API exactly as an outside client does:

```
KEY=$(php artisan rsx:api:key:temp --user=1 --expires="1 hour" --json | jq -r .data.key)
curl -H "Authorization: Bearer $KEY" http://localhost/api/v1/contacts
```

`rsx:man external_api` (COMMAND LINE) has the rest of the `rsx:api:*` namespace.

## Docblocks

The first paragraph is the docs description. An optional `@api-response` tag followed by
an indented example-JSON block is shown on the docs page. Add one to every endpoint.

Full framework reference: `php artisan rsx:man external_api`.
