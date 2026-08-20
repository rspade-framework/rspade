// @JS-THIS-01-EXCEPTION
/**
 * jQuery helper extensions for the RSX framework
 * These extensions add utility methods to jQuery's prototype
 * Note: 'this' references in jQuery extensions refer to jQuery objects by design
 */
class Rsx_Jq_Helpers {
    /**
     * Initialize jQuery extensions when the framework core is defined
     * This method is called during framework initialization
     */
    static _on_framework_core_define() {
        // Returns true if jquery selector matched an element
        $.fn.exists = function () {
            return this.length > 0;
        };

        // Returns true if jquery element is visible
        $.fn.is_visible = function () {
            return this.is(':visible');
        };

        // Scrolls to the target element, only scrolls up.  Todo: Create a version
        // of this that also scrolls only down, or both
        $.fn.scroll_up_to = function (speed = 0) {
            if (!this.exists()) {
                // console.warn("Could not find target element to scroll to");
                return;
            }

            if (!this.is_in_dom()) {
                // console.warn("Target element for scroll is not on dom");
                return;
            }

            let e_top = Math.round(this.offset().top);
            let s_top = $('body').scrollTop();
            if (e_top < 0) {
                let target = s_top + e_top;
                $('html, body').animate(
                    {
                        scrollTop: target,
                    },
                    speed
                );
            }
        };

        // $().is(":focus") - check if element has focus
        $.expr[':'].focus = function (elem) {
            return elem === document.activeElement && (elem.type || elem.href);
        };

        // Save native click behavior before override
        $.fn._click_native = $.fn.click;

        // Override .click() to call preventDefault by default
        // This prevents accidental page navigation/form submission - the correct behavior 95% of the time
        $.fn.click = function (handler) {
            // If no handler provided, trigger click event (jQuery .click() with no args)
            if (typeof handler === 'undefined') {
                return this._click_native();
            }

            // Attach click handler with automatic preventDefault
            return this.on('click', function (e) {
                // Save original preventDefault
                const original_preventDefault = e.preventDefault.bind(e);

                // Override preventDefault to show warning when called explicitly
                e.preventDefault = function() {
                    console.warn('event.preventDefault() is called automatically by RSpade .click() handlers and can be removed.');
                    return original_preventDefault();
                };

                // Call preventDefault before handler
                original_preventDefault();

                return handler.call(this, e);
            });
        };

        // Escape hatch: click handler without preventDefault for the 5% case
        $.fn.click_allow_default = function (handler) {
            if (typeof handler === 'undefined') {
                return this._click_native();
            }
            return this._click_native(handler);
        };

        // Async click handler with automatic button busy-state.
        //
        // Wraps the handler in a Button_Utils submitting cycle: on click the
        // element enters the busy state (rendered size locked, content swapped
        // for a loader, interaction blocked via pointer-events), the handler
        // runs, and the busy state clears when the returned promise settles.
        //
        // - Throws immediately if handler is not a function (fail loud).
        // - Auto preventDefault, consistent with .click().
        // - Re-entrancy guard: a click while already submitting is ignored. This
        //   covers programmatic .trigger('click') and double-taps that slip past
        //   pointer-events, which does not block synthetic click events.
        // - On settle, clears the busy state ONLY if the element is still in the
        //   DOM. A handler that navigates the SPA away detaches the button before
        //   its promise resolves; touching a detached node then is pointless, so
        //   the is_in_dom() guard skips it (no error).
        // - The handler's rejection is intentionally NOT caught. .finally()
        //   propagates it unchanged, so an error escapes as an unhandled
        //   rejection and reaches the global handler (Rsx._handle_unhandled_exception
        //   -> console + flash), exactly like any other uncaught async error.
        //   No swallow, no rethrow theater.
        $.fn.click_async = function (handler) {
            if (typeof handler !== 'function') {
                throw new Error('$.fn.click_async() requires a function handler');
            }

            return this.on('click', function (e) {
                e.preventDefault();

                const $el = $(this);

                // Re-entrancy guard - ignore clicks while a run is in flight
                if (Button_Utils.is_submitting($el)) {
                    return;
                }

                Button_Utils.set_submitting($el);

                // Run the handler and clear the busy state once it settles. The
                // chain is left unhandled on purpose so a rejection surfaces
                // globally rather than being swallowed here.
                Promise.resolve(handler.call(this, e)).finally(function () {
                    if ($el.is_in_dom()) {
                        Button_Utils.clear_submitting($el);
                    }
                });
            });
        };

        // Returns true if the jquery element exists in and is attached to the DOM
        $.fn.is_in_dom = function () {
            let $element = this;
            let _ancestor = function (HTMLobj) {
                while (HTMLobj.parentElement) {
                    HTMLobj = HTMLobj.parentElement;
                }
                return HTMLobj;
            };
            return _ancestor($element[0]) === document.documentElement;
        };

        // Returns true if the element is visible in the viewport
        $.fn.is_in_viewport = function () {
            let scrolltop = $(window).scrollTop() > 0 ? $(window).scrollTop() : $('body').scrollTop();

            let $element = this;

            const top_of_element = $element.offset().top;
            const bottom_of_element = $element.offset().top + $element.outerHeight();
            const bottom_of_screen = scrolltop + $(window).innerHeight();
            const top_of_screen = scrolltop;

            if (bottom_of_screen > top_of_element && top_of_screen < bottom_of_element) {
                return true;
            } else {
                return false;
            }
        };

        // Gets the tagname of a jquery element
        $.fn.tagname = function () {
            return this.prop('tagName').toLowerCase();
        };

        // Returns true if a href is not same domain
        $.fn.is_external = function () {
            const host = window.location.host;
            const link = $('<a>', {
                href: this.attr('href'),
            })[0].hostname;
            return link !== host;
        };

        // HTML5 form validation wrappers
        $.fn.checkValidity = function () {
            if (this.length === 0) return false;
            return this[0].checkValidity();
        };

        $.fn.reportValidity = function () {
            if (this.length === 0) return false;
            return this[0].reportValidity();
        };

        $.fn.requestSubmit = function () {
            if (this.length === 0) return this;
            this[0].requestSubmit();
            return this;
        };

        // Find related components by searching up the ancestor tree
        // Like .closest() but searches within ancestors instead of matching them
        $.fn.closest_sibling = function (selector) {
            let $current = this;
            let $parent = $current.parent();

            // Keep going up the tree until we hit body
            while ($parent.length > 0 && !$parent.is('body')) {
                // Search within this parent for the selector
                let $found = $parent.find(selector);
                if ($found.length > 0) {
                    return $found;
                }

                // Move up one level
                $parent = $parent.parent();
            }

            // If we reached body, search within body as well
            if ($parent.is('body')) {
                let $found = $parent.find(selector);
                if ($found.length > 0) {
                    return $found;
                }
            }

            // Return empty jQuery object if nothing found
            return $();
        };

        // Attach the CSRF token header to a settings object bound for the local
        // server, without clobbering any caller-supplied header. window.rsxapp.csrf
        // is populated (session-gated) at page render; when absent (anonymous
        // page) nothing is attached. Applied only to framework-local transports
        // (never to external cross-domain requests, which must not leak the token).
        const attach_csrf_header = function (settings) {
            if (typeof window !== 'undefined' && window.rsxapp && window.rsxapp.csrf) {
                settings.headers = settings.headers || {};
                if (!('X-CSRF-Token' in settings.headers)) {
                    settings.headers['X-CSRF-Token'] = window.rsxapp.csrf;
                }
            }
            return settings;
        };

        // Override $.ajax to prevent direct AJAX calls to local server
        // Developers must use the Ajax endpoint pattern: await Controller.method(params)
        const native_ajax = $.ajax;
        $.ajax = function (url, options) {
            // Handle both $.ajax(url, options) and $.ajax(options) signatures
            let settings;
            if (typeof url === 'string') {
                settings = options || {};
                settings.url = url;
            } else {
                settings = url || {};
            }

            // Check if this is a local request (relative URL or same domain)
            const request_url = settings.url || '';
            const is_relative = !request_url.match(/^https?:\/\//);
            const is_same_domain = request_url.startsWith(window.location.origin);
            const is_local_request = is_relative || is_same_domain;

            // Allow framework Ajax.call() to function (direct + batch transports).
            // Attach the CSRF token header here - the single chokepoint that covers
            // every Ajax.js call at once.
            if (settings.__local_integration === true) {
                return native_ajax.call(this, attach_csrf_header(settings));
            }

            // Allow file upload endpoint - requires native $.ajax for FormData support.
            // Same header attach covers every /_upload multipart caller.
            const is_file_upload = request_url === '/_upload' || request_url.endsWith('/_upload');
            if (is_file_upload) {
                return native_ajax.call(this, attach_csrf_header(settings));
            }

            // Block local AJAX requests that don't use the Ajax endpoint pattern
            if (is_local_request) {
                // Try to parse controller and action from URL
                let controller_name = null;
                let action_name = null;
                const url_match = request_url.match(/\/_rsx_api\/([^\/]+)\/([^\/\?]+)/);
                if (url_match) {
                    controller_name = url_match[1];
                    action_name = url_match[2];
                }

                let error_message = 'AJAX requests to localhost via $.ajax() are prohibited.\n\n';

                if (controller_name && action_name) {
                    error_message += `Instead of:\n`;
                    error_message += `  $.ajax({url: '${request_url}', ...})\n\n`;
                    error_message += `Use:\n`;
                    error_message += `  await ${controller_name}.${action_name}(parameters)\n\n`;
                } else {
                    error_message += `Use the Ajax endpoint pattern:\n`;
                    error_message += `  await Controller_Name.action_name(parameters)\n\n`;
                }

                error_message += `The controller method must have the #[Ajax_Endpoint] attribute.`;

                shouldnt_happen(error_message);
            }

            // Allow external requests (different domain)
            return native_ajax.call(this, settings);
        };
    }
}