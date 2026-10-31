<?php
/**
 * Portal_Auth_Bundle - the bundle for the client portal's sign-in pages and error pages
 *
 * THIS BUNDLE IS SERVED TO VISITORS WHO HAVE NOT SIGNED IN. The portal's login,
 * second-factor, register and password-reset pages, and its error pages,
 * are all reachable anonymously, so they do not render Portal_Bundle - the signed-in
 * portal's bundle, which carries every portal screen, every model and the component
 * library. They render this one, and whatever is listed below is published to anyone who
 * loads the portal's login page.
 *
 * So it names its assets BY FILE: the theme's global stylesheets, the Bootstrap build
 * they configure, and the two directories whose Blade views it serves. No directory from
 * the shared trees (rsx/theme, rsx/lib, rsx/models) appears here.
 *
 * These pages use framework components only (Turnstile_Input, Two_Factor_Challenge,
 * Passkey_Sign_In, Sso_Buttons), which arrive with the framework's own core bundle.
 * ADDING AN APPLICATION COMPONENT, MODEL OR SCRIPT TO ONE OF THEM MEANS ADDING ITS FILES
 * HERE - a component's .jqhtml and .js (and .scss), and its parents, both halves: the JS
 * base class it extends and the template its <Define:X extends="Y"> names.
 *
 * LOOK AT THE PAGE AFTER CHANGING THIS LIST. A missing JS class throws and names
 * itself. A missing stylesheet, and a missing extends="" parent template, throw nothing:
 * the page renders with a clean console and looks wrong.
 */

namespace Rsx\Portal\Auth;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class Portal_Auth_Bundle extends Rsx_Module_Bundle_Abstract
{
    /**
     * Define bundle assets
     */
    public static function define(): array
    {
        return [
            'include' => [
                // jQuery and Lodash are automatically included as required bundles
                'rsx/theme/variables.scss',     // The Sass scale and Bootstrap overrides (must be first)
                'rsx/theme/responsive.scss',    // Breakpoint mixins and utilities (after variables, before Bootstrap)
                'Bootstrap5_Src_Bundle',        // The custom Bootstrap build: .btn, .alert, .form-control
                'rsx/theme/*.scss',             // The theme's remaining own stylesheets - NOT its subdirectories,
                                                // which are the component library
                __DIR__,                        // The sign-in pages (required: it covers the views served)
                'rsx/portal/errors',            // The portal's error pages, which use this module's layout
            ],
        ];
    }
}
