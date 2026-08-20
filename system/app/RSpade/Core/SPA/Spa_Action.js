/**
 * Spa_Action - Base class for Spa action components
 *
 * An Action represents a page/route in the Spa. Each action class defines:
 * - Route pattern(s) via @route() decorator
 * - Layout to render within via @layout() decorator
 * - Associated PHP controller via @spa() decorator
 *
 * Actions receive URL parameters in this.args and load data in on_load().
 *
 * Example:
 *   @route('/contacts')
 *   @layout('Frontend_Layout')
 *   @spa('Frontend_Contacts_Controller::index')
 *   class Contacts_Index_Action extends Spa_Action {
 *       async on_load() {
 *           this.data.contacts = await Contacts_Controller.fetch_all();
 *       }
 *   }
 */
class Spa_Action extends Component {
    // constructor(args = {}, options = {}) {
    //     super(args, options);
    // }

    /**
     * Called during load phase to fetch data from server
     * Set this.data properties here
     */
    async on_load() {
        // Override in subclass
    }

    /**
     * Generate URL for this action class with given parameters
     * Static method for use without an instance
     */
    static url(params = {}) {
        const that = this;

        // Get routes from decorator metadata
        const routes = that._spa_routes || [];

        if (routes.length === 0) {
            console.error(`Action ${that.name} has no routes defined`);
            return '#';
        }

        // Use first route as the pattern
        // TODO: Implement smart route selection based on params
        const pattern = routes[0];

        return Spa.generate_url_from_pattern(pattern, params);
    }

    /**
     * Navigate to this action with given parameters
     * Static method for programmatic navigation
     */
    static dispatch(params = {}) {
        const that = this;
        const url = that.url(params);
        Spa.dispatch(url);
    }

    /**
     * Instance method: Generate URL with current args merged with new params
     */
    url(params = {}) {
        const merged_params = { ...this.args, ...params };
        return this.constructor.url(merged_params);
    }

    /**
     * Instance method: Navigate with current args merged with new params
     */
    dispatch(params = {}) {
        const url = this.url(params);
        Spa.dispatch(url);
    }

    // =========================================================================
    // Page Title & Breadcrumb System
    // =========================================================================

    /**
     * Page title displayed in the header/title area
     *
     * Defaults to the @title('...') decorator value (stored on the class as
     * _spa_title), so an action with a fixed title needs no override at all -
     * the decorator is the single declaration, and layouts paint it
     * synchronously at dispatch via get_static_title().
     *
     * Override for data-dependent titles (e.g., "Contact: John Smith C001") -
     * and start the override with `await this.await_loaded();` before reading
     * this.data, because callers do NOT wait for the action's load.
     *
     * @returns {Promise<string>} The page title
     */
    async page_title() {
        return this.constructor._spa_title ?? '(title not set)';
    }

    /**
     * The instantly-available static title, or null when the title is dynamic
     *
     * Returns the @title decorator value ONLY when the action does not override
     * page_title() - an override signals the decorator string is generic route
     * metadata (e.g. @title('User Details') on a view action), not the final
     * title, and painting it would flash a wrong title before the real one
     * resolves. Layouts use this as the zero-latency candidate at dispatch time.
     *
     * @returns {string|null} The static title, or null when the title is dynamic
     */
    get_static_title() {
        const overridden = this.page_title !== Spa_Action.prototype.page_title;

        return overridden ? null : (this.constructor._spa_title ?? null);
    }

    /**
     * Wait until this action's on_load() has completed (this.data populated)
     *
     * Call as the FIRST statement of any page_title()/breadcrumb method that
     * reads this.data - those methods are invoked at dispatch time, before the
     * load finishes. The 'load' lifecycle event is sticky (a late registration
     * fires immediately with the stored data), so this resolves at once for an
     * already-loaded action and is safe to call any number of times.
     *
     * @returns {Promise<void>}
     */
    async await_loaded() {
        await new Promise((resolve) => this.once('load', resolve));
    }

    /**
     * Breadcrumb label for this action
     *
     * Used when this action appears as a parent in another action's breadcrumb chain.
     * For entity pages (viewing a specific user, contact, etc.), return the entity name.
     * For list/index pages, return the section name.
     *
     * Default: Returns page_title()
     *
     * @returns {Promise<string>} The breadcrumb label
     */
    async breadcrumb_label() {
        return await this.page_title();
    }

    /**
     * Breadcrumb label when this action is the active/last crumb
     *
     * Use this to show a descriptive action name instead of the entity name
     * when the entity name is already visible in the page title above.
     *
     * Example: For a user profile view page:
     * - page_title() = "User Profile: John Smith U001"
     * - breadcrumb_label() = "John Smith" (for when it's a parent)
     * - breadcrumb_label_active() = "View User Profile" (avoids redundancy with title)
     *
     * Default: Returns breadcrumb_label()
     *
     * @returns {Promise<string>} The active breadcrumb label
     */
    async breadcrumb_label_active() {
        return await this.breadcrumb_label();
    }

    /**
     * Parent action URL for breadcrumb chain
     *
     * Return the URL of the parent action using Rsx.Route().
     * Return null if this action is a root (no parent breadcrumb).
     *
     * Example:
     *   async breadcrumb_parent() {
     *       return Rsx.Route('Settings_Users_Action');
     *   }
     *
     * @returns {Promise<string|null>} Parent URL or null if root
     */
    async breadcrumb_parent() {
        return null;
    }
}
