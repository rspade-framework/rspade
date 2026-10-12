<?php
/**
 * Login_Bundle - the bundle for the staff sign-in pages
 *
 * THIS BUNDLE IS SERVED TO VISITORS WHO HAVE NOT SIGNED IN. Every page in this module
 * (login, the second-factor screen, signup, invite acceptance, site selection) is
 * reachable anonymously, so whatever is listed below is published to anyone who loads
 * /login - and a bundle that includes a shared directory publishes everything in it:
 * the whole component library, every model's ORM definition, every controller's route
 * and Ajax stubs.
 *
 * So it names its assets BY FILE. No directory from the shared trees (rsx/theme,
 * rsx/lib, rsx/models) appears here; the one directory is this module's own, which a
 * bundle must include to cover the Blade views it serves.
 *
 * ADDING A FEATURE TO ONE OF THESE PAGES MEANS ADDING ITS FILES HERE:
 *
 *   - a component: its .jqhtml AND its .js (and its .scss when it has one);
 *   - AND ITS PARENTS, both halves: the JS base class it extends, and the template its
 *     <Define:X extends="Y"> names;
 *   - a model the page's JavaScript reads: that model's file;
 *   - a plain script or an npm Asset Bundle the component calls.
 *
 * Framework components (Rsx_Form, Form_Errors, Turnstile_Input, Two_Factor_Challenge,
 * Passkey_Sign_In, Sso_Buttons, Spinner) arrive with the framework's own core bundle and
 * are not listed.
 *
 * LOOK AT THE PAGE AFTER CHANGING THIS LIST. A missing JS class throws and names
 * itself. A missing stylesheet, and a missing extends="" parent template, throw nothing:
 * the page renders with a clean console and looks wrong.
 */

namespace Rsx\App\Login;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class Login_Bundle extends Rsx_Module_Bundle_Abstract
{
    /**
     * Define bundle assets
     */
    public static function define(): array
    {
        return [
            'include' => [
                // jQuery and Lodash are automatically included as required bundles

                // ---- The theme's global stylesheets, and the Bootstrap build they configure.
                'rsx/theme/variables.scss',     // The Sass scale and Bootstrap overrides (must be first)
                'rsx/theme/responsive.scss',    // Breakpoint mixins and utilities (after variables, before Bootstrap)
                'Bootstrap5_Src_Bundle',        // The custom Bootstrap build: .card, .btn, .alert, .form-control
                'rsx/theme/*.scss',             // The theme's remaining own stylesheets - NOT its subdirectories,
                                                // which are the component library

                // ---- Form_Field (signup, create-account), parent first.
                'rsx/theme/components/forms/form_field_abstract.jqhtml',
                'rsx/theme/components/forms/form_field_abstract.js',
                'rsx/theme/components/forms/form_field.jqhtml',
                'rsx/theme/components/forms/form_field.js',

                // ---- Text_Input and Hidden_Input (signup, create-account).
                'rsx/theme/components/inputs/text/text_input.jqhtml',
                'rsx/theme/components/inputs/text/text_input.js',
                'rsx/theme/components/inputs/hidden/hidden_input.jqhtml',
                'rsx/theme/components/inputs/hidden/hidden_input.js',

                // ---- Phone_Text_Input (signup): a JS-only child of Text_Input that formats
                //      through Formatters.phone(), which needs the libphonenumber npm global.
                'rsx/theme/components/inputs/text/phone_text_input.js',
                'rsx/lib/formatters.js',
                'Phone_Libphonenumber_Bundle',

                // ---- Modal: invite acceptance reports a failure with Modal.alert(), and the
                //      framework shows an unhandled Ajax error through it. Modal.select() is
                //      NOT usable here - Select_Input is not in this bundle.
                'rsx/lib/modal/modal.js',
                'rsx/lib/modal/rsx_modal.jqhtml',
                'rsx/lib/modal/rsx_modal.js',
                'rsx/lib/modal/rsx_modal.scss',

                __DIR__,                        // This module (required: it covers the views served)
            ],
        ];
    }
}
