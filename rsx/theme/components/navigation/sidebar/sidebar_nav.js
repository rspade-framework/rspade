/**
 * Sidebar_Nav
 *
 * Hierarchical sidebar navigation component with expandable sections.
 * See sidebar_nav.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Processes sections data and detects active items based on current URL
 * - Auto-expands parent items when child is active
 * - Handles click events for expandable items
 */
class Sidebar_Nav extends Component {
    on_create() {
        let that = this;

        // Process sections from args and detect active states
        let sections = that.args.sections || [];

        // Parse JSON string if needed
        if (typeof sections === 'string') {
            sections = json_decode(sections);
        }

        that.data = {
            sections: that.process_sections(sections),
        };
    }

    on_ready() {
        let that = this;

        // Attach click handler to chevron icons (expand/collapse only)
        that.$.find('.Sidebar_Nav__expand-icon').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $icon = $(this);
            const $parent = $icon.closest('.Sidebar_Nav__item-expandable');
            const $submenu = $parent.find('.Sidebar_Nav__submenu');

            // Toggle expanded state
            const is_expanded = $parent.hasClass('expanded');

            if (is_expanded) {
                $parent.removeClass('expanded');
                $submenu.slideUp(200);
            } else {
                $parent.addClass('expanded');
                $submenu.slideDown(200);
            }
        });

        // Attach click handler to expandable link labels (expand and navigate)
        that.$.find('.Sidebar_Nav__link-expandable').on('click', function (e) {
            e.preventDefault();
            const $link = $(this);
            const $parent = $link.parent();
            const $submenu = $parent.find('.Sidebar_Nav__submenu');

            // Check if already expanded
            const is_expanded = $parent.hasClass('expanded');

            if (!is_expanded) {
                // Expand first
                $parent.addClass('expanded');
                $submenu.slideDown(200, function () {
                    // After animation completes, navigate to first subitem
                    const first_subitem_href = $submenu.find('.Sidebar_Nav__subitem').first().attr('href');
                    if (first_subitem_href) {
                        window.location.href = first_subitem_href;
                    }
                });
            } else {
                // Already expanded, navigate immediately to first subitem
                const first_subitem_href = $submenu.find('.Sidebar_Nav__subitem').first().attr('href');
                if (first_subitem_href) {
                    window.location.href = first_subitem_href;
                }
            }
        });

        // Update active state for current URL (handles both standard and SPA routes)
        that.on_action(window.location.pathname);
    }

    // Executed whenever a page navigation occurs
    async on_action(url, action_name, args) {
        await this.ready();

        let that = this;

        // Remove active class from all nav items
        that.$.find('.Sidebar_Nav__link, .Sidebar_Nav__subitem').removeClass('active');
        that.$.find('.Sidebar_Nav__item-expandable').removeClass('expanded');

        // Check each nav item to see if it matches the current URL
        that.$.find('.Sidebar_Nav__link').each(function () {
            const $link = $(this);
            const href = $link.attr('href');

            if (that.is_url_active(href, url)) {
                $link.addClass('active');

                // If this is an expandable item, expand it
                const $parent = $link.closest('.Sidebar_Nav__item-expandable');
                if ($parent.exists()) {
                    $parent.addClass('expanded');
                }
            }
        });

        // Check subitems
        that.$.find('.Sidebar_Nav__subitem').each(function () {
            const $subitem = $(this);
            const href = $subitem.attr('href');

            if (that.is_url_active(href, url)) {
                $subitem.addClass('active');

                // Expand parent
                const $parent = $subitem.closest('.Sidebar_Nav__item-expandable');
                if ($parent.exists()) {
                    $parent.addClass('expanded');
                    // Also mark parent link as active
                    $parent.find('.Sidebar_Nav__link').first().addClass('active');
                }
            }
        });
    }

    process_sections(sections) {
        let that = this;
        const processed = [];

        for (const section of sections) {
            const processed_items = [];

            for (const item of section.items) {
                const processed_item = {
                    label: item.label,
                    icon: item.icon,
                    href: item.href,
                    active: that.is_url_active(item.href),
                    subitems: [],
                    has_active_child: false,
                };

                // Process subitems if they exist
                if (item.subitems && item.subitems.length > 0) {
                    for (const subitem of item.subitems) {
                        const processed_subitem = {
                            label: subitem.label,
                            href: subitem.href,
                            active: that.is_url_active(subitem.href),
                        };

                        processed_item.subitems.push(processed_subitem);

                        // Mark parent as having active child
                        if (processed_subitem.active) {
                            processed_item.has_active_child = true;
                        }
                    }
                }

                processed_items.push(processed_item);
            }

            processed.push({
                title: section.title,
                items: processed_items,
            });
        }

        return processed;
    }

    is_url_active(url, current_path = null) {
        // Use provided current_path or default to window.location.pathname
        if (current_path === null) {
            current_path = window.location.pathname;
        }

        // Exact match
        if (current_path === url) {
            return true;
        }

        // Check if current path starts with url (for nested routes)
        // But don't match if url is just "/"
        if (url !== '/' && current_path.startsWith(url)) {
            return true;
        }

        return false;
    }
}
