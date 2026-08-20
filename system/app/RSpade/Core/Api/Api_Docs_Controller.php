<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;

/**
 * Api_Docs_Controller - login-gated backing endpoints for the API documentation SPA.
 *
 * This controller is NOT part of the external API surface (it is a normal staff-session
 * controller): get_catalog / mint_tester_key are internal #[Ajax_Endpoint]s consumed by the
 * Core/Api/components jqhtml docs UI, and llm_catalog is a plain #[Route] that serves the
 * machine-readable JSON catalog for LLMs / integration agents. All three require a logged-in
 * session (the class-level #[Auth] gate); none uses the Bearer-authenticated Api_Dispatcher
 * pipeline.
 */
#[Auth('is_logged_in')]
class Api_Docs_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax: return the version list plus the resolved endpoint groups for one version.
     *
     * The version param (int) is validated against the catalog's version list; an unknown
     * version is a 404. When absent, the newest version is selected.
     *
     * @return array{versions: int[], resolved: array, selected_version: int}
     */
    #[Ajax_Endpoint]
    public static function get_catalog(Request $request, array $params = [])
    {
        $versions = Api_Catalog::get_versions();
        if (empty($versions)) {
            return ['versions' => [], 'resolved' => [], 'selected_version' => 0];
        }

        $requested = $params['version'] ?? null;
        if ($requested === null || $requested === '') {
            $selected = $versions[0]; // newest
        } else {
            $selected = (int) $requested;
            if (!in_array($selected, $versions, true)) {
                return response_not_found('Unknown API version');
            }
        }

        return [
            'versions' => $versions,
            'resolved' => Api_Catalog::resolve_for_version($selected),
            'selected_version' => $selected,
        ];
    }

    /**
     * Ajax: mint a short-lived (1 hour) live API key for the in-page request tester.
     *
     * The key is tied to the logged-in user and named "API Tester (temporary)" so it shows
     * (and can be revoked) in Settings > API Keys like any other key. The plaintext value is
     * returned here once and never recoverable afterward.
     *
     * @return array{key: string, expires_at: string}
     */
    #[Ajax_Endpoint]
    public static function mint_tester_key(Request $request, array $params = [])
    {
        $expires_at = now()->addHour();

        $result = Api_Key_Model::generate(
            Session::get_user_id(),
            'API Tester (temporary)',
            'live',
            null,
            $expires_at
        );

        return [
            'key' => $result['key'],
            'expires_at' => $expires_at->toIso8601String(),
        ];
    }

    /**
     * The machine-readable catalog for LLMs / integration agents (served application/json).
     *
     * Plain #[Route] (login-gated via pre_dispatch), NOT an external Api_Endpoint. The path is
     * extensionless on purpose: AssetHandler::is_asset_request() intercepts ANY '.json' path
     * as a static asset BEFORE route dispatch, so '/apidocs/catalog.json' could never reach a
     * controller. The literal '/apidocs/catalog' also outranks the SPA's '/apidocs/:version'
     * param route in RouteResolver priority, so both resolve correctly.
     */
    #[Route('/apidocs/catalog', methods: ['GET'])]
    public static function llm_catalog(Request $request, array $params = [])
    {
        return response()->json(Api_Catalog::get_llm_catalog());
    }
}
