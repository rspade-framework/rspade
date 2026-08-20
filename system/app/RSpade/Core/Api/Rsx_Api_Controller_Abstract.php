<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Rsx_Api_Controller_Abstract - base class for external REST API controllers.
 *
 * Every #[Api_Endpoint] controller MUST extend this class (enforced at scan time by
 * Api_Endpoint_ManifestSupport). It is a plain marker base: it carries NO behavior.
 *
 * Authentication, param validation, request logging and response building are ALL owned
 * by Api_Dispatcher, which branches off the main Dispatcher for any /api/vN/ URL. By the
 * time an endpoint method runs, the request is already authenticated (a headless,
 * cookie-less Session identity is established - Session::get_user()/get_site_id() work as
 * usual) and $params contains ONLY the validated #[Api_Param] values (no _method/_route/
 * _handler pollution).
 *
 * Response contract - an endpoint returns:
 *   - an array           -> 200 JSON (nested models serialize via toArray());
 *   - a model / collection -> 200 JSON (redacting toArray());
 *   - null               -> 204 No Content;
 *   - an Rsx_Api helper   -> its own status (created()=201, not_found()=404, ...).
 * Anything else fails loud. There is no {success,data} envelope; errors are
 * {"error":{"code","message","fields"?}} with real HTTP status codes.
 *
 * An optional static pre_dispatch(Request $request, array $params) is honored (a non-null
 * return short-circuits and is passed through response building), but authentication is NOT
 * its job - the dispatcher has already handled that.
 */
abstract class Rsx_Api_Controller_Abstract extends Rsx_Controller_Abstract
{
}
