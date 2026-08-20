/**
 * Exception_Handler
 *
 * Centralized exception display logic for unhandled exceptions.
 * Decides whether to show exception in SPA layout (debug mode) or as flash alert.
 *
 * Architecture:
 * - window error/unhandledrejection → Rsx._handle_unhandled_exception()
 * - Rsx triggers 'unhandled_exception' event (for logging via Debugger.js)
 * - Rsx disables SPA navigation
 * - Rsx calls Exception_Handler.display_unhandled_exception()
 * - Exception_Handler checks conditions and shows either:
 *   a) Debug error box in layout (if SPA, debug mode, action loading)
 *   b) Flash alert (when layout unavailable)
 *
 * Display Conditions for Layout Debug UI:
 * 1. Must be in SPA mode (window.rsxapp.is_spa)
 * 2. Must be in debug mode (window.rsxapp.debug)
 * 3. Spa.layout must exist with show_debug_exception() method
 * 4. Spa.layout.action must exist
 * 5. Action must still be loading (Spa.layout._action_is_loading === true)
 * 6. Developer has not suppressed display (suppress_display() not called)
 *
 * If conditions fail, falls back to flash alert.
 * If layout display throws, falls back to flash alert.
 *
 * Error Hints:
 * Common error patterns are detected and helpful hints are appended:
 * - "this.$ is not a function" / "that.$ is not a function" → suggest this.$.find()
 */
class Exception_Handler {

    /**
     * Developer-controlled flag to suppress all exception display UI
     * @type {boolean}
     */
    static _suppress_display = false;

    /**
     * Timestamp of last flash alert for rate limiting
     * @type {number}
     */
    static _last_exception_flash = 0;

    /**
     * Register exception handler during framework initialization
     * Called automatically by framework - do not call manually
     */
    static _on_framework_core_init() {
        // Listen for all unhandled exceptions to display them
        Rsx.on('unhandled_exception', function(payload) {
            // Extract exception and metadata from payload
            const exception = payload.exception;
            const meta = payload.meta || {};

            Exception_Handler.display_unhandled_exception(exception, meta);
        });

        // Patch console.error to add helpful hints for common JQHTML errors
        Exception_Handler._patch_console_error();
    }

    /**
     * Patch console.error to detect common error patterns and add helpful hints
     * This helps developers quickly understand common mistakes like:
     * - Using this.$ or that.$ as a function (should use this.$.find())
     */
    static _patch_console_error() {
        const original_console_error = console.error.bind(console);

        console.error = function(...args) {
            // Call original console.error first
            original_console_error(...args);

            // Check if this looks like a JQHTML callback error
            if (args.length >= 2 && typeof args[0] === 'string' && args[0].includes('[JQHTML]')) {
                const error = args[1];
                const error_message = error instanceof Error ? error.message : String(error);

                // Check for common "this.$ is not a function" or "that.$ is not a function" error
                if (error_message.includes('this.$ is not a function') ||
                    error_message.includes('that.$ is not a function')) {
                    original_console_error(
                        '%c[Hint] Did you mean this.$.find() or that.$.find()? ' +
                        'The component element (this.$) is a jQuery object, not a function.',
                        'color: #0dcaf0; font-style: italic;'
                    );
                }
            }
        };
    }

    /**
     * Suppress all exception display UI
     * Use this when you want to handle exceptions completely custom
     */
    static suppress_display() {
        Exception_Handler._suppress_display = true;
    }

    /**
     * Resume exception display UI after suppressing
     */
    static resume_display() {
        Exception_Handler._suppress_display = false;
    }

    /**
     * Display an unhandled exception using the most appropriate method
     *
     * Decision tree:
     * 1. If developer suppressed display → do nothing
     * 2. Log to console if from global handler (not already logged by catcher)
     * 3. If in SPA, debug mode, layout exists, action not ready → show in layout
     * 4. Otherwise → show flash alert
     *
     * @param {Error|string} exception - The exception to display
     * @param {Object} meta - Metadata about exception source
     * @param {string} meta.source - 'window_error', 'unhandled_rejection', or undefined
     */
    static display_unhandled_exception(exception, meta = {}) {
        // Developer explicitly suppressed all display
        if (Exception_Handler._suppress_display) {
            return;
        }

        // Log to console if from global handler (true unhandled error)
        // Skip if manually triggered - already logged by the catcher
        if (meta.source === 'window_error' || meta.source === 'unhandled_rejection') {
            console.error('[Exception_Handler] Unhandled exception:', exception);
        }

        // Try to show in layout if all conditions met
        if (Exception_Handler._should_show_in_layout()) {
            try {
                Spa.layout.show_debug_exception(exception);
                return; // Successfully shown in layout, don't show flash
            } catch (e) {
                // Failed to show in layout (maybe $content doesn't exist)
                // Fall through to flash alert
                console.warn('[Exception_Handler] Failed to show exception in layout:', e);
            }
        }

        // Show as flash alert when layout display unavailable
        Exception_Handler._show_flash_alert(exception);
    }

    /**
     * Check if we should attempt to show exception in SPA layout
     *
     * @returns {boolean}
     */
    static _should_show_in_layout() {
        // Must be in SPA mode
        if (!window.rsxapp || !window.rsxapp.is_spa) {
            return false;
        }

        // Must be in debug mode
        if (!window.rsxapp.debug) {
            return false;
        }

        // Spa must be loaded
        if (typeof Spa === 'undefined') {
            return false;
        }

        // Layout must exist and have the display method
        if (!Spa.layout || typeof Spa.layout.show_debug_exception !== 'function') {
            return false;
        }

        // Action must exist
        if (!Spa.layout.action) {
            return false;
        }

        // Check if action is still loading
        // During action load, Spa_Layout sets _action_is_loading flag
        if (!Spa.layout._action_is_loading) {
            return false;
        }

        return true;
    }

    /**
     * Show exception as flash alert (rate limited)
     *
     * @param {Error|string} exception
     */
    static _show_flash_alert(exception) {
        // Flash_Alert must be available
        if (typeof Flash_Alert === 'undefined') {
            return;
        }

        // Suppress errors during navigation grace period
        // After page navigation, pending requests from the old page may error out
        // These errors are not relevant to the new page
        if (typeof Spa !== 'undefined' && Spa.is_within_navigation_grace_period()) {
            console.log('[Exception_Handler] Suppressing error flash during navigation grace period');
            return;
        }

        // Extract message from Error object or string
        let error_text;
        if (exception instanceof Error) {
            error_text = exception.message;
        } else if (typeof exception === 'string') {
            error_text = exception;
        } else if (exception && typeof exception === 'object' && exception.message) {
            error_text = exception.message;
        } else {
            error_text = 'Unknown error';
        }

        // Rate limit: max 1 flash alert per second
        const now = Date.now();
        if (now - Exception_Handler._last_exception_flash >= 1000) {
            Exception_Handler._last_exception_flash = now;

            // Truncate long messages in debug mode, show generic message in production
            let message;
            if (window.rsxapp && window.rsxapp.debug) {
                message = error_text.length > 300
                    ? error_text.substring(0, 300) + '...'
                    : error_text;
            } else {
                message = 'An unhandled error has occurred, you may need to refresh your page';
            }

            Flash_Alert.error(message);
        }
    }
}
