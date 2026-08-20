class JS_Tree_Debug_Node extends Component {

    on_create() {
        this.is_expanded = (this.args.expand_depth ?? 1) > 0;
        // Track relationship loading states: { rel_name: 'pending'|'loading'|'loaded'|'error' }
        this.state.rel_states = {};
        // Store loaded relationship data: { rel_name: data }
        this.state.rel_data = {};
        // Store error messages: { rel_name: error_message }
        this.state.rel_errors = {};
    }

    on_ready() {
        // Relationships are never auto-loaded - they only load when explicitly expanded
    }

    toggle() {
        this.is_expanded = !this.is_expanded;
        this.$sid('children').toggle(this.is_expanded);
        this.$sid('toggle').toggleClass('js-tree-debug-collapsed', !this.is_expanded);
        this.$sid('preview_collapsed').toggle(!this.is_expanded);
        // Note: Relationships are NOT auto-loaded here - they have their own toggle handler
    }

    /**
     * Toggle a relationship node and load its data if not already loaded
     */
    toggle_relationship(rel_name) {
        const $container = this.$sid('rel_' + rel_name);
        const $toggle = $container.find('.js-tree-debug-toggle').first();
        const $children = $container.find('.js-tree-debug-rel-children').first();
        const $preview = $container.find('.js-tree-debug-preview-collapsed').first();

        const is_expanded = !$toggle.hasClass('js-tree-debug-collapsed');

        if (is_expanded) {
            // Collapse
            $toggle.addClass('js-tree-debug-collapsed');
            $children.hide();
            $preview.show();
        } else {
            // Expand
            $toggle.removeClass('js-tree-debug-collapsed');
            $children.show();
            $preview.hide();

            // Load if not already loaded
            if (!this.state.rel_states[rel_name] || this.state.rel_states[rel_name] === 'pending') {
                this._load_relationship(rel_name);
            }
        }
    }

    /**
     * Load a relationship and update the UI
     */
    async _load_relationship(rel_name) {
        // Validate the relationship function exists
        const obj = this.args.data;
        if (!obj || typeof obj[rel_name] !== 'function') {
            return;
        }

        this.state.rel_states[rel_name] = 'loading';
        this._update_relationship_ui(rel_name);

        try {
            const result = await obj[rel_name]();
            this.state.rel_states[rel_name] = 'loaded';
            this.state.rel_data[rel_name] = result;
            this._update_relationship_ui(rel_name);
        } catch (e) {
            this.state.rel_states[rel_name] = 'error';
            this.state.rel_errors[rel_name] = e.message || 'Error loading relationship';
            this._update_relationship_ui(rel_name);
        }
    }

    /**
     * Update the UI for a relationship after loading
     */
    _update_relationship_ui(rel_name) {
        const $container = this.$sid('rel_' + rel_name);
        const $children = $container.find('.js-tree-debug-rel-children').first();
        const $preview = $container.find('.js-tree-debug-preview-collapsed').first();

        const state = this.state.rel_states[rel_name];
        const data = this.state.rel_data[rel_name];
        const error = this.state.rel_errors[rel_name];

        // Update preview text
        if (state === 'loading') {
            $preview.html('<span class="js-tree-debug-loading">loading...</span>');
        } else if (state === 'error') {
            $preview.html('<span class="js-tree-debug-error">error</span>');
        } else if (state === 'loaded') {
            const type = JS_Tree_Debug_Node.get_type(data);
            $preview.text(JS_Tree_Debug_Node.get_preview(data, type) || JS_Tree_Debug_Node.format_value(data, type));
        }

        // Update children content
        $children.empty();

        if (state === 'loading') {
            $children.html('<div class="js-tree-debug-leaf js-tree-debug-loading">Loading...</div>');
        } else if (state === 'error') {
            $children.html('<div class="js-tree-debug-leaf js-tree-debug-error">"' + this._escape_html(error) + '"</div>');
        } else if (state === 'loaded') {
            this._render_relationship_result($children, data, rel_name);
        }
    }

    /**
     * Render the result of a relationship fetch into the container
     * Renders entries directly (not wrapped in another node) so user doesn't have to double-expand
     */
    _render_relationship_result($container, data, rel_name) {
        const type = JS_Tree_Debug_Node.get_type(data);

        // Handle null/undefined
        if (type === 'null' || type === 'undefined') {
            $container.html('<div class="js-tree-debug-leaf js-tree-debug-empty">(no data)</div>');
            return;
        }

        // Handle empty array
        if (type === 'array' && data.length === 0) {
            $container.html('<div class="js-tree-debug-leaf js-tree-debug-empty">(empty array)</div>');
            return;
        }

        // Handle empty object
        if (type === 'object' && Object.keys(data).length === 0) {
            $container.html('<div class="js-tree-debug-leaf js-tree-debug-empty">(empty object)</div>');
            return;
        }

        // Handle primitive values (shouldn't happen but be safe)
        if (type !== 'array' && type !== 'object') {
            $container.html('<div class="js-tree-debug-leaf"><span class="js-tree-debug-value js-tree-debug-' + type + '">' +
                this._escape_html(JS_Tree_Debug_Node.format_value(data, type)) + '</span></div>');
            return;
        }

        // Render entries directly into container (no wrapper node)
        const entries = JS_Tree_Debug_Node.get_entries(data, type);
        const expand_depth = Math.max(0, (this.args.expand_depth ?? 1) - 1);
        const show_class_names = this.args.show_class_names ?? false;

        for (const [key, value] of entries) {
            const val_type = JS_Tree_Debug_Node.get_type(value);
            const is_expandable = val_type === 'object' || val_type === 'array';

            if (is_expandable) {
                // Create a node for expandable values
                const $node = $('<div>');
                $container.append($node);
                $node.component('JS_Tree_Debug_Node', {
                    data: value,
                    expand_depth: expand_depth,
                    label: key,
                    show_class_names: show_class_names
                });
            } else {
                // Render leaf value directly
                $container.append(
                    '<div class="js-tree-debug-leaf">' +
                    '<span class="js-tree-debug-key">' + this._escape_html(String(key)) + '</span>' +
                    '<span class="js-tree-debug-colon">: </span>' +
                    '<span class="js-tree-debug-value js-tree-debug-' + val_type + '">' +
                    this._escape_html(JS_Tree_Debug_Node.format_value(value, val_type)) +
                    '</span></div>'
                );
            }
        }
    }

    _escape_html(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Static helpers for template use
    static get_type(val) {
        if (val === null) return 'null';
        if (val === undefined) return 'undefined';
        if (is_array(val)) return 'array';
        return typeof val;
    }

    static get_preview(val, type) {
        if (type === 'array') return 'Array(' + val.length + ')';
        if (type === 'object') {
            const keys = Object.keys(val);
            if (keys.length === 0) return '{}';
            if (keys.length <= 3) return '{' + keys.join(', ') + '}';
            return '{' + keys.slice(0, 3).join(', ') + ', ...}';
        }
        return '';
    }

    static get_entries(data, type) {
        if (type === 'array') return data.map((v, i) => [i, v]);
        return Object.entries(data || {}).sort((a, b) => a[0].localeCompare(b[0]));
    }

    static format_value(val, type) {
        if (type === 'string') return '"' + val + '"';
        if (type === 'null') return 'null';
        if (type === 'undefined') return 'undefined';
        return str(val);
    }

    /**
     * Get the class name of an object if it's a named class instance (not generic Object/Array)
     * @param {*} val - Value to check
     * @returns {string|null} - Class name or null if generic/primitive
     */
    static get_class_name(val) {
        if (val === null || val === undefined) return null;
        if (Array.isArray(val)) return null;
        if (typeof val !== 'object') return null;
        const name = val.constructor?.name;
        if (!name || name === 'Object') return null;
        return name;
    }

    /**
     * Get fetchable relationships for an object
     * Returns array of relationship names if object's class has get_relationships()
     * @param {*} obj - Object to check
     * @returns {string[]} - Array of relationship names, or empty array
     */
    static get_object_relationships(obj) {
        try {
            if (!obj || typeof obj !== 'object') return [];
            if (Array.isArray(obj)) return [];

            // Check if constructor has get_relationships static method
            const ctor = obj.constructor;
            if (!ctor || typeof ctor.get_relationships !== 'function') return [];

            // Get relationships and validate it returns an array
            const relationships = ctor.get_relationships();
            if (!Array.isArray(relationships)) return [];

            // Filter to only relationships that are actually functions on the object
            return relationships.filter(name => {
                return typeof name === 'string' && typeof obj[name] === 'function';
            });
        } catch (e) {
            // Any error, just return empty array
            return [];
        }
    }
}
