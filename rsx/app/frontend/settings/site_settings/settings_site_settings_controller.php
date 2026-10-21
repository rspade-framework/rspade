<?php

namespace Rsx\App\Frontend\Settings\SiteSettings;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Session\Session;

/**
 * The current site's own settings: its name.
 */
#[Auth('is_logged_in', 'can_manage_site_settings')]
class Frontend_Settings_Site_Settings_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: the current site's settings, shaped as the form's values, plus the
     * name column's length so the form's $max_length is the schema's number.
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_settings(Request $request, array $params = [])
    {
        $site = Site_Model::findOrFail(Session::get_site_id());

        return [
            'name_max_length' => Site_Model::field_length('name'),
            'form_data' => [
                'name' => $site->name,
            ],
        ];
    }

    /**
     * Ajax endpoint: save the site settings form.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function update(Request $request, array $params = [])
    {
        $name = trim((string) ($params['name'] ?? ''));

        if ($name === '') {
            return response_form_error('Please correct the errors below.', [
                'name' => 'Site name is required',
            ]);
        }

        $max_length = Site_Model::field_length('name');
        if (mb_strlen($name) > $max_length) {
            return response_form_error('Please correct the errors below.', [
                'name' => "Site name must be {$max_length} characters or fewer",
            ]);
        }

        $site = Site_Model::findOrFail(Session::get_site_id());
        $site->name = $name;
        $site->save();

        return [
            'name' => $site->name,
        ];
    }
}
