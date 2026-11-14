<?php

namespace Rsx\App\Apidocs;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api_Docs;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Session\Session;
use Rsx\Permission;

/**
 * Apidocs_Controller - this template application's API reference console.
 *
 * THE WHOLE INTEGRATION IS THE TWO METHODS BELOW: A GATE AND ONE CALL. The console - its
 * markup, its components, its styles and its bundle - is framework property; an application
 * declares a route, decides who may reach it, and calls one function, with the simplicity of
 * phpinfo(). There is nothing here to restyle, subclass or keep a copy of. What an
 * application MAY hand it is content: __sections() below is this API's own prose (its
 * conventions and its error codes), Markdown the console lists under "Guides".
 *
 * The framework declares no route for it and no visibility setting: the application chooses
 * the path, and the #[Auth] gate on that route is the access control. Point it somewhere
 * else, gate it behind your own permission check, or do not declare it at all and the
 * console does not exist on your install.
 *
 * THE GATE HERE IS API ACCESS, NOT A ROLE. The class stays #[Auth('public')] because the
 * gate this console actually wants is the same question the API itself asks of a Bearer
 * key: Session::has_api_access() (users.is_api_access_enabled) AND the can_use_api
 * permission Main::pre_dispatch requires - __may_use_api() below. An #[Auth] check answers "may this user use this surface"
 * from the auth realm's vocabulary; this predicate is identity state that the API layer,
 * the settings page and this console all consult, so all three read the one predicate and
 * the console cannot drift from what the API will actually accept. has_api_access() creates
 * no session, so an anonymous visitor is refused without one being minted for them.
 *
 * A signed-out visitor and a signed-in one without API access are handled by the SAME call:
 * Error_Screens::unauthorized() sends the unidentified caller to the login route with this
 * URL threaded through Login_Redirect, and renders a themed 403 for the identified one. It
 * is the framework's own full-page denial renderer, which is what a #[Route] needs - an
 * Ajax envelope like response_unauthorized() would reach a browser as JSON.
 *
 * The console links to <mount>/openapi.json, so openapi() below serves the same document to
 * a person signed in to this browser, under the console's gate. A program never uses it: it
 * reads the framework's GET /api/vN/openapi.json with its API key.
 */
#[Auth('public')]
class Apidocs_Controller extends Rsx_Controller_Abstract
{
    /**
     * The console itself.
     *
     * Listing is left unrestricted here (the default is to restrict to the supplied API
     * key's permissions) because a public reference with nothing in it until you paste a key
     * would not be much of a reference. An installation whose API surface differs per
     * customer wants the default instead.
     */
    #[Route('/apidocs', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        if (!static::__may_use_api()) {
            return Error_Screens::unauthorized($request);
        }

        return Rsx_Api_Docs::page(restrict_to_key: false, sections: static::__sections());
    }

    /**
     * This API's own sections on the console: what holds for every endpoint, which no single
     * endpoint's docblock can say. Each is a title and Markdown (a GFM table is a table); the
     * framework renders and sanitizes it and owns every pixel of how it looks.
     */
    private static function __sections(): array
    {
        return [
            [
                'title' => 'Conventions',
                'markdown' => <<<'MD'
                    Every request carries an API key, `Authorization: Bearer rsx_...`. There is no
                    cookie or session authentication on this surface. A key acts as the user who
                    minted it, within that user's site, and may be narrowed further by scopes or
                    minted read-only.

                    **GET reads, POST writes.** No other verb is served. A POST body is a JSON object.

                    **Every answer is a JSON object** with a real HTTP status, and carries
                    `_duration_ms` and `_completed_at`. A list arrives inside an object:

                    ```json
                    {"items": [], "meta": {"page": 1, "per_page": 20, "total": 0, "total_pages": 0}}
                    ```

                    **Errors** share one shape - branch on `error.code` and the status, never on the
                    message:

                    ```json
                    {"error": {"code": "validation", "message": "...", "fields": {"email": "..."}}}
                    ```

                    **Values are plain.** An amount of money is a decimal string with two places
                    (`"7708.80"`), returned and accepted that way - never a JSON number for a
                    stored amount; an amount larger than its field holds is a validation error
                    on that field. Rich text is its HTML string; send back the string you read and
                    the stored value does not change.

                    **Retrying a write safely.** Name a write with `_request_ref` (a string unique per
                    request you mean). A retry with the same reference is answered from the original
                    request instead of running again (`_replayed: true`).
                    MD,
            ],
            [
                'title' => 'Errors',
                'markdown' => <<<'MD'
                    The codes this API answers, and what a client does about each.

                    | Status | `error.code` | Meaning | What to do |
                    |---|---|---|---|
                    | 400 | `invalid_json` | The body is not valid JSON. | Fix the request. |
                    | 401 | `auth_required` | No `Authorization: Bearer` key was sent. | Send the key. |
                    | 401 | `unauthorized` | The key is invalid, expired or revoked, or its user is disabled. | Supply a valid key. |
                    | 403 | `account_refused` | The key's user may not use the API. | Ask an administrator to grant API access. |
                    | 403 | `read_only_key` | A read-only key sent something other than a GET. | Use a read+write key. |
                    | 403 | `insufficient_scope` | The key's scopes do not include this path (`error.required` names it). | Mint a key with a wider scope. |
                    | 403 | `forbidden` | The key's user lacks the permission this endpoint requires. | Ask an administrator for the permission. |
                    | 404 | `not_found` | No such route, or no such record visible to this key. | Check the path and the id. |
                    | 405 | `method_not_allowed` | A verb other than GET or POST. | Use GET or POST. |
                    | 409 | `request_ref_reused` | The `_request_ref` already names a different request; nothing ran. | Send a new reference. |
                    | 410 | `request_response_expired` | The request already ran, and its stored answer has been pruned. | Read the record's current state; do not resend. |
                    | 410 | `api_version_retired` | This version no longer serves the path (`error.versions` lists those that do). | Move to a listed version. |
                    | 422 | `validation` | A parameter is missing, undeclared or invalid (`error.fields` names each). | Correct the named fields. |
                    | 500 | `internal_error` | A fault on the server, or a service it needs was down. The write may or may not have committed. | Resend with the same `_request_ref`: a committed write is answered from history, never run twice. |
                    MD,
            ],
        ];
    }

    /**
     * The OpenAPI 3.1 description (every version), downloaded from the console's
     * openapi.json link by a person with API access. A program reads the framework's
     * GET /api/vN/openapi.json with its key instead.
     *
     * The framework hands back the document as an array and this route builds the response,
     * because headers are the controller's business. Content-Disposition: attachment because
     * the thing a developer does with this file is feed it to a generator - so it is served
     * as a download rather than rendered into the browser's JSON viewer.
     */
    #[Route('/apidocs/openapi.json', methods: ['GET'])]
    public static function openapi(Request $request, array $params = [])
    {
        if (!static::__may_use_api()) {
            return Error_Screens::unauthorized($request);
        }

        return response()->json(Rsx_Api_Docs::openapi_document(), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="openapi.json"',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * May the signed-in user use the API: the framework's identity switch
     * (Session::has_api_access(), users.is_api_access_enabled) AND this application's
     * permission (can_use_api, PERM_API_ACCESS) - the same pair a bearer-key request must
     * pass. The identity switch is asked first; it creates no session, so an anonymous
     * visitor is refused without one being minted.
     */
    private static function __may_use_api(): bool
    {
        return Session::has_api_access() && Permission::can_use_api();
    }
}
