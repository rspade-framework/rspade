/**
 * Spa_Layout - Base class for Spa layouts
 *
 * Layouts provide the persistent wrapper (header, nav, footer) around actions or sublayouts.
 * They render to their parent's $content area and contain their own $content for children.
 *
 * Sublayouts: Multiple @layout decorators create a chain of nested layouts.
 * Each layout persists independently - if navigating between pages with the same
 * outer layout but different inner layouts, only the differing parts are recreated.
 *
 * Requirements:
 * - Must have an element with $sid="content" where children (sublayouts or actions) render
 * - Persists across navigations as long as it remains in the layout chain
 *
 * Properties set by Spa:
 * - this.action - Reference to the current action (bottom of chain)
 *
 * Hook methods that can be overridden:
 * - on_action(url, action_name, args) - Called when any action is dispatched
 *   All layouts in the chain receive this with the final action's info.
 */
class Spa_Layout extends Component {
    on_create() {
        console_debug('Spa_Layout', `${this.constructor.name} created`);
    }

    /**
     * Get the content container where actions render
     * @returns {jQuery} The content element
     */
    $content() {
        return this.$sid('content');
    }

    /**
     * Resolve the current action's page title through the two-stage ladder
     *
     * This is the ONE way a layout obtains a title, so every layout paints in
     * the same order:
     *   1. SYNCHRONOUS - the action's static title (its @title decorator value,
     *      present only when the action does not override page_title()). Zero
     *      latency: no await has happened yet when it is painted.
     *   2. When there is no static title, the caller's `placeholder` (a
     *      layout's own cached title for this URL, if it keeps one).
     *   3. ASYNCHRONOUS - the awaited page_title(), painted only when it
     *      differs from what is already on screen.
     *
     * The action is dispatched before its load completes, so stage 3 can take
     * as long as the action's data does; an action reading this.data in
     * page_title() awaits Spa_Action.await_loaded() first.
     *
     * @param {function(string):void} paint - Called with each title to display
     * @param {string|null} placeholder - Shown when the action has no static title
     * @returns {Promise<string|null>} The live title (for the caller to cache)
     */
    async resolve_page_title(paint, placeholder = null) {
        if (!this.action) {
            return null;
        }

        let painted = this.action.get_static_title();

        if (painted === null && placeholder) {
            painted = placeholder;
        }

        if (painted) {
            paint(painted);
        }

        const live_title = await this.action.page_title();

        if (live_title && live_title !== painted) {
            paint(live_title);
        }

        return live_title;
    }

    /**
     * Show a debug exception message at the top of the content area
     * Prepends a styled error box with the exception message
     *
     * @param {string|Error} exception - Error message or Error object to display
     */
    show_debug_exception(exception) {
        const $content = this.$content();
        if (!$content || !$content.length) {
            console.error('[Spa_Layout] Cannot show debug exception: content element not found');
            return;
        }

        // Extract message from Error object or use string directly
        let message;
        if (exception instanceof Error) {
            message = exception.message;
        } else if (typeof exception === 'string') {
            message = exception;
        } else if (exception && typeof exception === 'object' && exception.message) {
            message = exception.message;
        } else {
            message = String(exception);
        }

        // Create error box with inline styles (no framework dependencies)
        const error_html = `
            <div style="border: 2px solid #dc3545; background-color: #ffe6e6; color: #000; padding: 15px; margin-bottom: 20px;">
                <strong>Fatal Error:</strong> ${this._escape_html(message)}
            </div>
        `;

        // Prepend to content area
        $content.prepend(error_html);
    }

    /**
     * Escape HTML to prevent XSS
     * @private
     */
    _escape_html(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Hook called when a new action is set
     * Override this in subclasses to react to action changes.
     *
     * on_action can / should be implemented as a async function. but nothing is waiting for it, this is
     * just defined this way for convienence to make clear that async code can be used and is appropriate
     * for on_action.
     *
     * If it is necessary to wait for the action itself to reach ready state, add:
     *      await this.action.ready();
     * to the concrete implementation of on_action.
     *
     * @param {string} url - The URL being navigated to
     * @param {string} action_name - Name of the action class
     * @param {object} args - URL parameters and query params
     */
    async on_action(url, action_name, args) {
        // Empty by default - override in subclass
    }

    on_ready() {
        console_debug('Spa_Layout', `${this.constructor.name} ready`);
    }
}
