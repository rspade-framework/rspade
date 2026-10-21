/**
 * Frontend Spa Layout - Main layout for frontend SPA
 *
 * Provides the persistent structure (header, footer) that wraps all frontend actions.
 * The content area ($sid="content") is where actions render.
 *
 * Header Management:
 * - Caches page title in session storage for instant display on revisit
 * - Uses Rsx_Breadcrumb_Resolver for progressive breadcrumb loading with caching
 * - Updates document.title to "RSX - {page_title}"
 * - Actions can override: page_title(), breadcrumb_label(), breadcrumb_label_active(), breadcrumb_parent()
 *
 * Page Actions:
 * - An action's optional page_actions() names a COMPONENT for the header's button cluster:
 *   it returns {component: 'Name', args: {...}} (args optional), or null for none, and may
 *   be async. The layout instantiates it on the cluster on every navigation; the component
 *   owns its markup and its handlers. See Record_Page_Actions and List_Page_Actions in
 *   rsx/theme/components/page/.
 */
class Frontend_Spa_Layout extends Spa_Layout {
    static TITLE_CACHE_PREFIX = 'header_text:';

    on_create() {
        this._breadcrumb_cancel = null;
        this._current_chain = null; // Track current chain for progressive updates
        this._current_url = null;   // Track current URL for caching
        this.state = {
            // The signed-in site user (a hydrated User_Model), published on every page as
            // window.rsxapp.user - the header's profile link and user dropdown read it.
            user: window.rsxapp.user || null,
            nav_sections: [
                {
                    title: 'Overview',
                    items: [
                        {
                            label: 'Dashboard',
                            icon: 'bi-house-door',
                            route: 'Dashboard_Index_Action',
                            href: Rsx.Route('Dashboard_Index_Action'),
                        },
                        {
                            label: 'Action Log',
                            icon: 'bi-journal-text',
                            route: 'Action_Logs_Index_Action',
                            href: Rsx.Route('Action_Logs_Index_Action'),
                        },
                    ],
                },
                {
                    title: 'Business',
                    items: [
                        {
                            label: 'Clients',
                            icon: 'bi-people',
                            route: 'Clients_Index_Action',
                            href: Rsx.Route('Clients_Index_Action'),
                        },
                        {
                            label: 'Contacts',
                            icon: 'bi-person-rolodex',
                            route: 'Contacts_Index_Action',
                            href: Rsx.Route('Contacts_Index_Action'),
                        },
                        {
                            label: 'Projects',
                            icon: 'bi-folder',
                            route: 'Projects_Index_Action',
                            href: Rsx.Route('Projects_Index_Action'),
                        },
                        {
                            label: 'Parties',
                            icon: 'bi-diagram-3',
                            route: 'Party_Index_Action',
                            href: Rsx.Route('Party_Index_Action'),
                        },
                        {
                            label: 'Tasks',
                            icon: 'bi-check2-square',
                            route: 'Tasks_Index_Action',
                            href: Rsx.Route('Tasks_Index_Action'),
                        },
                    ],
                },
                {
                    title: 'Financial',
                    items: [
                        {
                            label: 'Reports',
                            icon: 'bi-bar-chart',
                            route: 'Reports_Index_Action',
                            href: Rsx.Route('Reports_Index_Action'),
                        },
                    ],
                },
                {
                    title: 'Administration',
                    items: [
                        {
                            label: 'Users',
                            icon: 'bi-person-badge',
                            route: 'Settings_User_Management_Index_Action',
                            href: Rsx.Route('Settings_User_Management_Index_Action'),
                        },
                        {
                            label: 'Settings',
                            icon: 'bi-gear',
                            route: 'Settings_General_Action',
                            href: Rsx.Route('Settings_General_Action'),
                        },
                        {
                            // The framework's developer console (/_sys). Its gate is
                            // is_sysadmin (a developer), so the nav filter below shows it
                            // to developers only; it opens in its own tab.
                            label: 'Developer',
                            icon: 'bi-terminal',
                            route: '_Sys_Dashboard_Action',
                            href: Rsx.Route('_Sys_Dashboard_Action'),
                            target: '_blank',
                        },
                    ],
                },
                {
                    title: 'Recent',
                    items: [],
                },
            ],
        };

        // NAV HONESTY. A link this user's gates would deny must not appear.
        // Permission.can_access() answers from the target action's own @auth list
        // (bundle metadata) against the render-time grant map - no network call and
        // no permission rule duplicated at the link site. Tighten a page's gates and
        // its nav entry disappears on the next render. A section that had items and
        // lost them all is dropped so no heading is left orphaned; a section that was
        // authored empty (Recent) is left alone.
        for (const section of this.state.nav_sections) {
            section.items = section.items.filter(
                (item) => !item.route || Permission.can_access(item.route)
            );
        }
        this.state.nav_sections = this.state.nav_sections.filter(
            (section) => section.items.length > 0 || section.title === 'Recent'
        );
    }

    // Override on_action to update active nav, handle wide view, and update header
    on_action(url, action_name, args) {
        // Update sidebar navigation active state
        this.sid('sidebar_nav').on_action(url, action_name, args);

        // Handle content-area width from action property. Clear all width
        // modifiers first, then apply the one this action requests, so the
        // persistent content element carries the right state across SPA
        // navigation.
        const $content = this.$content();
        $content.removeClass(
            'Frontend_Spa_Layout__page-content--constrained ' +
            'Frontend_Spa_Layout__page-content--constrained-wider ' +
            'Frontend_Spa_Layout__page-content--scaffolded'
        );

        if (this.action.scaffolded) {
            // The action composes with Page_Scaffold, which owns max-width,
            // centering, and page padding. The layout yields all width/padding
            // control so the scaffold's 2300px + --page-pad are neither doubled
            // nor capped. (Semantic view-page refactor, Batch C reconciliation.)
            $content.addClass('Frontend_Spa_Layout__page-content--scaffolded');
        } else if (this.action.full_width) {
            // Full width, but the layout keeps its own page padding.
        } else if (this.action.constrained_wider) {
            $content.addClass('Frontend_Spa_Layout__page-content--constrained-wider');
        } else {
            $content.addClass('Frontend_Spa_Layout__page-content--constrained');
        }

        // Store current URL for caching
        this._current_url = url;

        // Handle page title (cached then live)
        this._update_page_title(url);

        // Cancel any previous breadcrumb stream
        if (this._breadcrumb_cancel) {
            this._breadcrumb_cancel();
        }

        // Stream breadcrumbs progressively with caching
        // Previous breadcrumbs stay visible until on_chain is called
        this._breadcrumb_cancel = Rsx_Breadcrumb_Resolver.stream(
            this.action,
            url,
            {
                on_chain: (chain) => this._on_breadcrumb_chain(chain),
                on_label_update: (index, label) => this._on_breadcrumb_label_update(index, label),
                on_complete: (chain) => this._on_breadcrumb_complete(chain)
            }
        );

        // Render page action buttons if action defines them
        this._render_page_actions();
    }

    /**
     * Update page title
     *
     * Spa_Layout.resolve_page_title() owns the ordering: the action's static
     * @title value paints synchronously when it has one, otherwise the title
     * cached for this URL stands in, and the awaited page_title() replaces
     * whichever was shown once it differs.
     */
    async _update_page_title(url) {
        const $title = this.$sid('page_title');
        if (!$title || !$title.exists()) return;

        const cache_key = Frontend_Spa_Layout.TITLE_CACHE_PREFIX + url;

        // Clear current title
        $title.html('&nbsp;');

        // The title is plain text - often a record's own name - so it is written with .text().
        const paint = (title) => {
            $title.text(title);
            document.title = 'RSX - ' + title;
        };

        try {
            const live_title = await this.resolve_page_title(paint, Rsx_Storage.session_get(cache_key));
            if (live_title) {
                // Cache for next time
                Rsx_Storage.session_set(cache_key, live_title);
            }
        } catch (e) {
            console.warn('Error resolving page title:', e);
        }
    }

    /**
     * Called when breadcrumb chain structure is available
     */
    _on_breadcrumb_chain(chain) {
        this._current_chain = chain;
        this._render_breadcrumbs();
    }

    /**
     * Called when an individual breadcrumb label resolves
     */
    _on_breadcrumb_label_update(index, label) {
        if (this._current_chain && this._current_chain[index]) {
            this._current_chain[index].label = label;
            this._current_chain[index].resolved = true;
            this._render_breadcrumbs();
        }
    }

    /**
     * Called when all breadcrumb labels have resolved
     */
    _on_breadcrumb_complete(chain) {
        this._current_chain = chain;
        this._render_breadcrumbs();
    }

    /**
     * Render the current breadcrumb chain
     */
    _render_breadcrumbs() {
        const $breadcrumbs = this.$sid('page_breadcrumbs');
        if (!$breadcrumbs || !$breadcrumbs.exists()) return;

        if (this._current_chain && this._current_chain.length > 0) {
            try {
                $breadcrumbs.component('Breadcrumb_Nav', { crumbs: this._current_chain });
            } catch (e) {
                console.warn('Breadcrumb render skipped (navigation race condition):', e.message);
            }
        } else {
            $breadcrumbs.html('&nbsp;');
        }
    }

    /**
     * Render the current action's page actions into the page header.
     *
     * page_actions() returns {component, args} or null. Not wrapped: a page_actions() that
     * throws, or returns anything else, is a broken page and fails loud.
     */
    async _render_page_actions() {
        const $actions = this.$sid('page_actions');
        if (!$actions || !$actions.exists()) return;

        const action = this.action;

        // Clear previous action buttons (stops the previous page's component)
        $actions.empty();

        if (typeof action.page_actions !== 'function') {
            return;
        }

        const spec = await action.page_actions();

        // A slower page_actions() from an earlier navigation must not paint over the
        // current action's header.
        if (this.action !== action || !spec) {
            return;
        }

        if (typeof spec !== 'object' || typeof spec.component !== 'string') {
            throw new Error(
                action.constructor.name + '.page_actions() must return {component, args} or null'
            );
        }

        $('<div></div>').appendTo($actions).component(spec.component, spec.args || {});
    }
}
