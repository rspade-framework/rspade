<?php

namespace Rsx\App\Frontend\Settings\SiteSettings;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Session\Session;

/**
 * The current site's own settings: its name, and its client portal - whether the site
 * offers one (sites.is_portal_enabled) and, when PORTAL_CUSTOM_DOMAINS is not 'off', the
 * custom domain it is served on (sites.portal_domain).
 */
#[Auth('is_logged_in', 'can_manage_site_settings')]
class Frontend_Settings_Site_Settings_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: the current site's settings, shaped as the form's values, plus the
     * column lengths the form's $max_length takes and the read-only portal facts:
     *
     *   portal_feature_enabled   PORTAL_ENABLED - false hides the portal fields entirely
     *   custom_domains_enabled   PORTAL_CUSTOM_DOMAINS is not 'off' - shows portal_domain
     *   portal_url               the site's portal address as it is served now, or null
     *                            when the site has no client portal
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_settings(Request $request, array $params = [])
    {
        $site = Site_Model::findOrFail(Session::get_site_id());
        $custom_domains_enabled = Rsx_Portal_Url::custom_domains() !== Rsx_Portal_Url::CUSTOM_DOMAINS_OFF;

        $form_data = [
            'name' => $site->name,
            'is_portal_enabled' => $site->is_portal_enabled ? '1' : '0',
        ];

        if ($custom_domains_enabled) {
            $form_data['portal_domain'] = (string) $site->portal_domain;
        }

        $address = Rsx_Portal_Url::for_site((int) $site->id);

        return [
            'name_max_length' => Site_Model::field_length('name'),
            'portal_domain_max_length' => Site_Model::field_length('portal_domain'),
            'portal_feature_enabled' => Rsx_Portal_Url::is_enabled(),
            'custom_domains_enabled' => $custom_domains_enabled,
            'portal_url' => $address === false ? null : $address['origin'] . $address['prefix'],
            'form_data' => $form_data,
        ];
    }

    /**
     * Ajax endpoint: save the site settings form.
     *
     * is_portal_enabled and portal_domain are written only when submitted (an absent key
     * is untouched - the fields are not rendered while the portal, or custom domains, are
     * off for the install). A blank portal_domain clears it. The domain is validated by
     * Site_Model::normalize_portal_domain() - the one spelling the portal's host lookup
     * compares - and must not be another site's.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Ajax_Endpoint]
    public static function update(Request $request, array $params = [])
    {
        $errors = [];
        $site = Site_Model::findOrFail(Session::get_site_id());

        $name = trim((string) ($params['name'] ?? ''));
        $max_length = Site_Model::field_length('name');

        if ($name === '') {
            $errors['name'] = 'Site name is required';
        } elseif (mb_strlen($name) > $max_length) {
            $errors['name'] = "Site name must be {$max_length} characters or fewer";
        }

        $writes_portal_flag = array_key_exists('is_portal_enabled', $params) && Rsx_Portal_Url::is_enabled();
        $is_portal_enabled = null;

        if ($writes_portal_flag) {
            $is_portal_enabled = (string) $params['is_portal_enabled'];

            if ($is_portal_enabled !== '0' && $is_portal_enabled !== '1') {
                $errors['is_portal_enabled'] = 'Choose whether this site has a client portal';
            }
        }

        $writes_domain = array_key_exists('portal_domain', $params)
            && Rsx_Portal_Url::custom_domains() !== Rsx_Portal_Url::CUSTOM_DOMAINS_OFF;
        $portal_domain = null;

        if ($writes_domain) {
            $domain_max_length = Site_Model::field_length('portal_domain');
            $entered = trim((string) $params['portal_domain']);

            if (mb_strlen($entered) > $domain_max_length) {
                $errors['portal_domain'] = "Portal domain must be {$domain_max_length} characters or fewer";
            } else {
                try {
                    $portal_domain = Site_Model::normalize_portal_domain($entered);
                } catch (\InvalidArgumentException $e) {
                    $errors['portal_domain'] = 'Enter a hostname such as portal.example.com';
                }

                // The column is UNIQUE across every row, soft-deleted sites included.
                if ($portal_domain !== null && !isset($errors['portal_domain'])
                    && Site_Model::withTrashed()->where('portal_domain', $portal_domain)->where('id', '!=', $site->id)->exists()) {
                    $errors['portal_domain'] = 'Another site already uses this portal domain';
                }
            }
        }

        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        $site->name = $name;

        if ($writes_portal_flag) {
            $site->is_portal_enabled = $is_portal_enabled === '1';
        }

        if ($writes_domain) {
            $site->portal_domain = $portal_domain;
        }

        $site->save();

        return [
            'name' => $site->name,
        ];
    }
}
