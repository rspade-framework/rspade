/**
 * Error_Screens - what a terminal SPA outcome RENDERS (client side)
 *
 * The JS twin of App\RSpade\Core\Errors\Error_Screens. Same three outcomes, same
 * names, rendered into the ACTIVE layout's content area instead of as a new page:
 *
 *     Error_Screens.unauthorized()   // an @auth gate denied the target action
 *     Error_Screens.not_found()      // no action matches the URL
 *     Error_Screens.fatal(error)     // the action failed to boot or render
 *
 * No navigation, no server round trip: the layout, nav and session chrome stay
 * alive around the error body, so the user is still inside the application.
 *
 * THE BODIES ARE APP-OWNED THEME CODE. This class only resolves the target
 * container and mounts the matching component from
 * rsx/theme/components/feedback/errors/ - the same components the three-state
 * pattern uses through Universal_Error_Page_Component. Customizing an error
 * screen in the SPA means editing those components; no override machinery is
 * involved on this side (contrast the PHP twin, which is a class-override).
 *
 * See: php artisan rsx:man auth_gates (ERROR SCREENS)
 */
class Error_Screens {
    /**
     * Render the unauthorized body for a denied surface
     *
     * @param {object} options
     * @param {string} [options.message] Sentence shown to the user
     * @param {string} [options.section] What was being accessed (e.g. "Billing")
     * @returns {object} The mounted component instance
     */
    static unauthorized(options = {}) {
        return Error_Screens._render('Unauthorized_Error_Page_Component', {
            message: options.message || 'You do not have permission to view this page',
            section: options.section || '',
        });
    }

    /**
     * Render the not-found body for a URL nothing claims
     *
     * @param {object} options
     * @param {string} [options.record_type] What is missing (default "Page")
     * @param {string} [options.back_label] Label for the escape link
     * @param {string} [options.back_url] Target of the escape link
     * @returns {object} The mounted component instance
     */
    static not_found(options = {}) {
        return Error_Screens._render('Not_Found_Error_Page_Component', {
            record_type: options.record_type || 'Page',
            back_label: options.back_label || 'Return to home',
            back_url: options.back_url || '/',
        });
    }

    /**
     * Render the fatal body for a boot/render failure
     *
     * The error object itself is NOT rendered - it goes to the console, where a
     * developer has the stack, and the page shows the message only. An error page
     * is the one surface a user always sees; it must not become a debug dump.
     *
     * @param {Error|string} error The failure
     * @returns {object} The mounted component instance
     */
    static fatal(error) {
        console.error('[Error_Screens] Fatal:', error);

        const message = is_string(error)
            ? error
            : (error && error.message ? error.message : 'An unexpected error occurred.');

        return Error_Screens._render('Generic_Error_Page_Component', {
            error_data: message,
        });
    }

    /**
     * Mount an error component in the active content area
     *
     * Tears down the mounted action first: a denied or failed action must not be
     * left running behind the error body. The container itself is preserved (it is
     * the layout's $sid="content" element, found by id on the next navigation) -
     * the body is mounted on a child of it, exactly like ordinary page content.
     *
     * @param {string} component_name Theme component to mount
     * @param {object} args Component arguments
     * @returns {object} The mounted component instance
     */
    static _render(component_name, args) {
        if (!jqhtml.get_registered_templates().includes(component_name)) {
            throw new Error(
                `[Error_Screens] Missing theme component '${component_name}'. ` +
                'The SPA error screens render the app-owned components in ' +
                'rsx/theme/components/feedback/errors/ - restore it or change ' +
                'Error_Screens to name the component your theme provides.'
            );
        }

        const current_action = Spa.action();
        if (current_action) {
            current_action.stop();
        }
        Spa._action = null;

        const layouts = Spa._collect_all_layouts();
        const deepest_layout = layouts.length ? layouts[layouts.length - 1] : null;

        // #spa-root is the initial-boot case: the bootstrap page has rendered, but
        // no layout has been mounted yet (a first-load denial).
        const $target = deepest_layout ? deepest_layout.$sid('content') : $('#spa-root');
        if (!$target || !$target.length) {
            throw new Error('[Error_Screens] No layout content area and no #spa-root to render into');
        }

        $target.empty();
        Spa._clear_container_attributes($target);

        const $host = $('<div>');
        $target.append($host);
        $host.component(component_name, args);

        console_debug('Spa', `Error_Screens rendered ${component_name}`);

        return $host.component();
    }
}
