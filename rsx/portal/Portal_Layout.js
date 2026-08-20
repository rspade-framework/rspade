/**
 * Portal Layout - Main layout for client portal SPA
 *
 * Provides the persistent structure (header, sidebar, footer) that wraps all portal actions.
 * The content area ($sid="content") is where actions render.
 *
 * Simpler than Frontend_Spa_Layout since portal has fewer features.
 */
class Portal_Layout extends Spa_Layout {

    /**
     * Called when a new action is loaded
     *
     * @param {string} url The URL being navigated to
     * @param {string} action_name The action class name
     * @param {object} args The action arguments
     */
    async on_action(url, action_name, args) {
        // Update active nav state
        this._update_active_nav(url);

        // Scaffolded reconciliation (semantic view-page refactor, Batch J).
        // A portal action composing with Page_Scaffold declares `scaffolded = true`.
        // Stamp the page area so the nested scaffold owns its own outer padding +
        // max-width/centering; the persistent content element is reset per
        // navigation, so an unconverted portal page restores the default. This is the
        // OUTER layer (mirrors Frontend_Spa_Layout, Batch C); the workspace sublayout
        // reconciles its own content pane (Portal_Workspace_Layout, --page-pad: 0).
        this.$content().toggleClass('Portal_Layout__page--scaffolded', !!this.action.scaffolded);

        // Update the browser tab title. Spa_Layout.resolve_page_title() paints the
        // action's static @title value immediately when it has one, then repaints
        // with the awaited page_title() if it resolves to something different.
        await this.resolve_page_title((title) => {
            document.title = `Portal - ${title}`;
        });
    }

    /**
     * Update active state on navigation items
     */
    _update_active_nav(current_url) {
        // Guard: skip if component not yet rendered (this.$ is only set after boot)
        if (typeof this.$ !== 'function') {
            return;
        }

        // Remove active class from all nav links
        this.$('.Portal_Layout__nav-link').removeClass('active');

        // Find and activate matching link
        this.$('.Portal_Layout__nav-link').each((i, el) => {
            const href = $(el).attr('href');
            if (href && current_url.startsWith(href)) {
                $(el).addClass('active');
            }
        });
    }
}
