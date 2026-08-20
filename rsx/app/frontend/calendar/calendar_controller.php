<?php

namespace Rsx\App\Frontend\Calendar;

use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Frontend_Calendar_Controller - Ajax endpoints for the calendar feature.
 *
 * No endpoints yet: Calendar_Index_Action renders entirely client-side. The
 * class-level gate is declared up front so the first endpoint added here is
 * login-gated by default rather than by remembering to say so.
 */
#[Auth('is_logged_in')]
class Frontend_Calendar_Controller extends Rsx_Controller_Abstract
{
}
