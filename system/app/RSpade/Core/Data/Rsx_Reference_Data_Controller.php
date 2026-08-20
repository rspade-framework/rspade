<?php

namespace App\RSpade\Core\Data;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Country_Model;
use App\RSpade\Core\Models\Region_Model;

/**
 * RSX:USE
 * Rsx_Reference_Data_Controller - Provides reference data for forms (countries, states, etc.)
 *
 * Ajax endpoints that return standardized reference data for dropdowns and forms.
 * Returns data in format compatible with Select_Input widgets: [{value: 'code', label: 'name'}, ...]
 *
 * Usage from widgets:
 *     let countries = await Rsx_Reference_Data_Controller.countries();
 *     let states = await Rsx_Reference_Data_Controller.states({country: 'US'});
 *
 * AUTHORIZATION: the class gate is 'public' - this is public reference data (ISO
 * country/state tables), needed by unauthenticated forms such as signup with a
 * state selector.
 */
#[Auth('public')]
#[Auth_Realm('any')]
class Rsx_Reference_Data_Controller extends Rsx_Controller_Abstract
{
    /**
     * Get list of all countries with ISO codes
     * Queries Country_Model database for ISO 3166-1 country data
     * Returns array of {value: country_code, label: country_name} sorted alphabetically
     */
    #[Ajax_Endpoint]
    public static function countries(Request $request, array $params = [])
    {
        return Country_Model::enabled()
            ->orderBy('name')
            ->get()
            ->map(fn($c) => ['value' => $c->alpha2, 'label' => $c->name])
            ->toArray();
    }

    /**
     * Get list of states/provinces for a country
     * Queries Region_Model database for ISO 3166-2 subdivision data
     *
     * @param Request $request
     * @param array $params - Expected: ['country' => 'US']
     */
    #[Ajax_Endpoint]
    public static function states(Request $request, array $params = [])
    {
        $country = $params['country'] ?? 'US';

        return Region_Model::where('country_alpha2', $country)
            ->enabled()
            ->orderBy('name')
            ->get()
            ->map(fn($r) => ['value' => $r->code, 'label' => $r->name])
            ->toArray();
    }
}
