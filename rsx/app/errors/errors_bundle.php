<?php
/**
 * Errors_Bundle - the bundle for the staff error pages
 *
 * THIS BUNDLE IS SERVED TO VISITORS WHO HAVE NOT SIGNED IN. A 404 is answered for
 * anybody, so whatever is listed below is published to anyone who requests a URL that
 * does not exist - and a bundle that includes a shared directory publishes everything in
 * it: the whole component library, every model's ORM definition, every controller's
 * route and Ajax stubs.
 *
 * So it names its assets BY FILE: the theme's global stylesheets, the Bootstrap build
 * they configure, and this directory. No directory from the shared trees (rsx/theme,
 * rsx/lib, rsx/models) appears here; the one directory is this module's own, which a
 * bundle must include to cover the Blade views it serves.
 *
 * An error page is the last thing a broken request renders, so it uses no application
 * component, model or script at all. ADDING ONE MEANS ADDING ITS FILES HERE - a
 * component's .jqhtml and .js (and .scss), and its parents, both halves: the JS base
 * class it extends and the template its <Define:X extends="Y"> names.
 *
 * LOOK AT THE PAGE AFTER CHANGING THIS LIST. A missing JS class throws and names
 * itself. A missing stylesheet, and a missing extends="" parent template, throw nothing:
 * the page renders with a clean console and looks wrong.
 */

namespace Rsx\App\Errors;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class Errors_Bundle extends Rsx_Module_Bundle_Abstract
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
                'Bootstrap5_Src_Bundle',        // The custom Bootstrap build: .card, .btn
                'rsx/theme/*.scss',             // The theme's remaining own stylesheets - NOT its subdirectories,
                                                // which are the component library
                __DIR__,                        // This module (required: it covers the views served)
            ],
        ];
    }
}
