/**
 * Width_Group - Synchronized min-width for element groups
 *
 * Makes multiple elements share the same min-width, determined by the widest
 * element in the group. Useful for button groups, card layouts, or any UI
 * where elements should have consistent widths.
 *
 * =============================================================================
 * API
 * =============================================================================
 *
 * $(".selector").width_group("group-name")
 *     Adds matched elements to a named width group. All elements in the group
 *     will have their min-width set to match the widest element.
 *
 *     - Elements are tracked individually (not by selector)
 *     - Calling again with same group name adds more elements to the group
 *     - If element is already in the same group, no-op (idempotent)
 *     - If element is in a different group, it's moved to the new group
 *     - Recalculates immediately and on window resize (debounced 100ms)
 *     - Returns the jQuery object for chaining
 *
 * $.width_group_destroy("group-name")
 *     Removes all tracking for the named group. Clears min-width styles from
 *     elements still in the DOM and removes the resize listener if no groups
 *     remain.
 *
 * $.width_group_recalculate("group-name")
 *     Manually triggers recalculation for a group. Useful after dynamically
 *     changing element content.
 *
 * $.width_group_recalculate()
 *     Recalculates all groups.
 *
 * =============================================================================
 * AUTOMATIC CLEANUP
 * =============================================================================
 *
 * On each resize (or manual recalculate), elements are checked for DOM presence
 * using element.isConnected. Disconnected elements are automatically removed
 * from tracking. When all elements in a group are gone, the group is destroyed.
 * When all groups are destroyed, the resize listener is removed.
 *
 * This means SPA navigation that removes elements will automatically clean up
 * without explicit destroy calls.
 *
 * =============================================================================
 * EXAMPLE USAGE
 * =============================================================================
 *
 *     // Make all action buttons the same width
 *     $(".action-buttons .btn").width_group("action-buttons");
 *
 *     // Add more buttons to the same group later
 *     $(".extra-btn").width_group("action-buttons");
 *
 *     // Explicit cleanup (optional - auto-cleans when elements removed)
 *     $.width_group_destroy("action-buttons");
 *
 *     // After changing button text, recalculate
 *     $(".action-buttons .btn").text("New Label");
 *     $.width_group_recalculate("action-buttons");
 *
 * =============================================================================
 * HOW IT WORKS
 * =============================================================================
 *
 * 1. On width_group() call: elements are added to the named group registry
 * 2. Calculation runs immediately:
 *    a. Remove min-width from all elements in group (measure natural width)
 *    b. Find max scrollWidth across all connected elements
 *    c. Apply max as min-width to all connected elements
 * 3. Child components are found via shallowFind('.Component') and their
 *    ready events trigger debounced recalculation
 * 4. On window resize (debounced 100ms): recalculate all groups
 * 5. Disconnected elements are pruned on each calculation
 *
 */

// @JS-THIS-01-EXCEPTION
class Width_Group {
    // Registry of groups: { "group-name": [element, element, ...] }
    static _groups = {};

    // Debounced recalculate handler (shared by resize and component ready)
    static _debounced_recalculate = null;

    // Whether resize listener is attached
    static _resize_attached = false;

    /**
     * Initialize jQuery extensions
     */
    static _on_framework_core_define() {
        if (Rsx.is_ssr()) return;

        /**
         * Add elements to a named width group
         * @param {string} group_name - Name of the width group
         * @returns {jQuery} The jQuery object for chaining
         */
        $.fn.width_group = function (group_name) {
            if (!group_name || typeof group_name !== 'string') {
                console.error('width_group() requires a group name string');
                return this;
            }

            // Initialize group if needed
            if (!Width_Group._groups[group_name]) {
                Width_Group._groups[group_name] = [];
            }

            // Add each element to the group
            this.each(function () {
                const element = this;
                const current_group = element._width_group;

                // Already in this group - no-op
                if (current_group === group_name) {
                    return;
                }

                // In a different group - remove from old group first
                if (current_group && Width_Group._groups[current_group]) {
                    const old_group = Width_Group._groups[current_group];
                    const index = old_group.indexOf(element);
                    if (index !== -1) {
                        old_group.splice(index, 1);
                        element.style.minWidth = '';
                    }
                    // Clean up empty group
                    if (old_group.length === 0) {
                        delete Width_Group._groups[current_group];
                    }
                }

                // Add to new group
                Width_Group._groups[group_name].push(element);
                element._width_group = group_name;
            });

            // Ensure resize listener is attached
            Width_Group._attach_resize_listener();

            // Listen for child component ready events
            this.each(function () {
                $(this).shallowFind('.Component').each(function () {
                    const component = $(this).component();
                    if (component) {
                        component.on('ready', () => {
                            Width_Group._schedule_recalculate();
                        });
                    }
                });
            });

            // Calculate immediately
            Width_Group._calculate_group(group_name);

            return this;
        };

        /**
         * Destroy a width group, removing tracking and styles
         * @param {string} group_name - Name of the width group to destroy
         */
        $.width_group_destroy = function (group_name) {
            Width_Group.destroy(group_name);
        };

        /**
         * Manually recalculate a group or all groups
         * @param {string} [group_name] - Optional group name. If omitted, recalculates all.
         */
        $.width_group_recalculate = function (group_name) {
            if (group_name) {
                Width_Group._calculate_group(group_name);
            } else {
                Width_Group._calculate_all();
            }
        };
    }

    /**
     * Destroy a named group
     * @param {string} group_name
     */
    static destroy(group_name) {
        const elements = Width_Group._groups[group_name];
        if (!elements) {
            return;
        }

        // Clear min-width and group tracking from elements
        for (const element of elements) {
            if (element.isConnected) {
                element.style.minWidth = '';
            }
            delete element._width_group;
        }

        // Remove group from registry
        delete Width_Group._groups[group_name];

        // Detach resize listener if no groups remain
        Width_Group._maybe_detach_resize_listener();
    }

    /**
     * Schedule a debounced recalculation of all groups
     * Used by resize handler and component ready events
     * @private
     */
    static _schedule_recalculate() {
        if (!Width_Group._debounced_recalculate) {
            Width_Group._debounced_recalculate = debounce(() => {
                Width_Group._calculate_all();
            }, 100);
        }
        Width_Group._debounced_recalculate();
    }

    /**
     * Attach the resize listener if not already attached
     * @private
     */
    static _attach_resize_listener() {
        if (Width_Group._resize_attached) {
            return;
        }

        $(window).on('resize.width_group', () => {
            Width_Group._schedule_recalculate();
        });
        Width_Group._resize_attached = true;
    }

    /**
     * Detach resize listener if no groups remain
     * @private
     */
    static _maybe_detach_resize_listener() {
        if (Object.keys(Width_Group._groups).length === 0 && Width_Group._resize_attached) {
            $(window).off('resize.width_group');
            Width_Group._resize_attached = false;
        }
    }

    /**
     * Calculate and apply min-width for all groups
     * @private
     */
    static _calculate_all() {
        for (const group_name of Object.keys(Width_Group._groups)) {
            Width_Group._calculate_group(group_name);
        }
    }

    /**
     * Calculate and apply min-width for a specific group
     * @param {string} group_name
     * @private
     */
    static _calculate_group(group_name) {
        let elements = Width_Group._groups[group_name];
        if (!elements || elements.length === 0) {
            return;
        }

        // Filter to only connected elements, clean up disconnected ones
        elements = elements.filter(el => {
            if (el.isConnected) {
                return true;
            }
            delete el._width_group;
            return false;
        });

        // Update registry with pruned list
        Width_Group._groups[group_name] = elements;

        // If all elements are gone, destroy the group
        if (elements.length === 0) {
            delete Width_Group._groups[group_name];
            Width_Group._maybe_detach_resize_listener();
            return;
        }

        // Step 1: Remove min-width to measure natural width
        for (const element of elements) {
            element.style.minWidth = '';
        }

        // Step 2: Find the maximum natural width
        let max_width = 0;
        for (const element of elements) {
            // scrollWidth gives the full content width including overflow
            const width = element.scrollWidth;
            if (width > max_width) {
                max_width = width;
            }
        }

        // Step 3: Apply max width as min-width to all elements
        if (max_width > 0) {
            for (const element of elements) {
                element.style.minWidth = max_width + 'px';
            }
        }
    }
}
