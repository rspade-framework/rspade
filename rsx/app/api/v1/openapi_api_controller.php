<?php

namespace Rsx\App\Api\V1;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Core\Api\Rsx_Api_Docs;

/**
 * OpenAPI API - this API's own OpenAPI 3.1 description, read with an API key.
 *
 * The same document /apidocs/openapi.json serves to a signed-in browser, here for an
 * integration that holds only a key: a client generator, a contract test or an agent
 * toolchain fetching the description of the version it calls. It is this version's
 * surface (info.version = v1) and carries x-build, the build that answered.
 *
 * Answered through Rsx_Api::document(), so the body is the document exactly - without the
 * _duration_ms / _completed_at every other answer carries, which an OpenAPI root does not
 * admit. A new API version adds its own copy under its own vN/ directory, naming its
 * version.
 *
 * AUTH. The gates every key-holder of this API passes (is_logged_in, can_use_api); the
 * document describes calls, not data, so no data permission is asked. A scoped key reaches
 * it only when a scope grants this path.
 */
#[Auth('is_logged_in', 'can_use_api')]
#[Api_Resource('OpenAPI')]
class Openapi_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * The OpenAPI 3.1 description of API v1.
     *
     * @api-response
     * {
     *   "openapi": "3.1.0",
     *   "info": { "title": "RSpade API", "version": "v1" },
     *   "paths": {},
     *   "x-build": {
     *     "commit": "2e262230537ac17f9897277513a9c6b6341f83b5",
     *     "built_at": "2026-11-10T23:00:29.000Z",
     *     "framework_revision": "2e262230537ac17f9897277513a9c6b6341f83b5",
     *     "code_key": "4f1c2a9be07d3e55"
     *   }
     * }
     */
    #[Api_Endpoint('/api/v1/openapi.json', methods: ['GET'])]
    public static function document(Request $request, array $params = [])
    {
        return Rsx_Api::document(Rsx_Api_Docs::openapi_document(null, 1));
    }
}
