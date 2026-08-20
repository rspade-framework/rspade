<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend\Settings\General;

use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Frontend_Settings_General_Controller - Ajax endpoints for Settings > General.
 *
 * No endpoints yet: Settings_General_Action is a static overview screen. The
 * class-level gate is declared up front so the first endpoint added here is
 * login-gated by default rather than by remembering to say so.
 */
#[Auth('is_logged_in')]
class Frontend_Settings_General_Controller extends Rsx_Controller_Abstract
{
}
