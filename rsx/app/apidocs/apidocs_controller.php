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
 * THE WHOLE INTEGRATION IS THE TWO METHODS BELOW, AND ALL THEY DO IS GATE. The console -
 * its markup, its components, its styles and its bundle - is framework property; an
 * application declares a route, decides who may reach it, and calls one function, with the
 * simplicity of phpinfo(). There is nothing here to customize, subclass or keep a copy of.
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
 * The page and the document are separate methods so they CAN be gated differently - a public
 * openapi.json beside a staff-only console is a normal arrangement. Here they share the one
 * gate, because a document describing an API you may not call is not useful to you.
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

        return Rsx_Api_Docs::page(restrict_to_key: false);
    }

    /**
     * The OpenAPI 3.1 description, for client generators, Postman and agent toolchains.
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
