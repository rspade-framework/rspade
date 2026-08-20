<?php

namespace Rsx\App\Frontend\Settings\ApiKeys;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;
use Rsx\App\Frontend\Settings\ApiKeys\Api_Keys_DataGrid;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Settings_Api_Keys_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: Fetch DataGrid data for API keys
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Api_Keys_DataGrid::fetch($params);
    }

    /**
     * Ajax endpoint: Create new API key
     *
     * Returns the plaintext key (only time it's visible).
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function create_key(Request $request, array $params = [])
    {
        $errors = [];

        // Validate name
        $name = trim($params['name'] ?? '');
        if (empty($name)) {
            $errors['name'] = 'Key name is required';
        } elseif (strlen($name) > 255) {
            $errors['name'] = 'Key name must be 255 characters or less';
        }

        if (!empty($errors)) {
            return response_error(Ajax::ERROR_VALIDATION, $errors);
        }

        $user = Session::get_user();
        if (!$user) {
            return response_error(Ajax::ERROR_AUTH_REQUIRED);
        }

        // Generate the key
        $result = Api_Key_Model::generate(
            user_id: $user->id,
            name: $name,
            environment: 'live'
        );

        return [
            'id' => $result['model']->id,
            'name' => $result['model']->name,
            'key' => $result['key'], // Plaintext - only shown once!
            'key_prefix' => $result['model']->key_prefix,
            'created_at' => $result['model']->created_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Ajax endpoint: Revoke an API key
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function revoke_key(Request $request, array $params = [])
    {
        $key_id = $params['id'] ?? null;

        if (!$key_id) {
            return response_error(Ajax::ERROR_VALIDATION, ['id' => 'Key ID is required']);
        }

        $user = Session::get_user();
        if (!$user) {
            return response_error(Ajax::ERROR_AUTH_REQUIRED);
        }

        // Find the key and verify ownership
        $key = Api_Key_Model::where('id', $key_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$key) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'API key not found');
        }

        if ($key->is_revoked) {
            return response_error(Ajax::ERROR_VALIDATION, ['id' => 'Key is already revoked']);
        }

        $key->revoke();

        return [
            'id' => $key->id,
            'message' => 'API key revoked successfully',
        ];
    }
}
