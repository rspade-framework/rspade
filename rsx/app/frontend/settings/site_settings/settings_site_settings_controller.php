<?php

namespace Rsx\App\Frontend\Settings\SiteSettings;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Settings_Site_Settings_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: save the site settings form.
     *
     * SCAFFOLDING - it persists nothing yet. What it DOES do is validate, because the
     * asterisk beside "Site Name" is a promise that a server rule exists: the form
     * itself checks nothing, so if this endpoint did not check, a blank site name
     * would sail through and no one would find out until an integration hit the
     * endpoint directly.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function update(Request $request, array $params = [])
    {
        $name = trim($params['name'] ?? '');

        if ($name === '') {
            return response_form_error('Please correct the errors below.', [
                'name' => 'Site name is required',
            ]);
        }

        return [
            'message' => 'Site settings updated successfully',
        ];
    }
}
