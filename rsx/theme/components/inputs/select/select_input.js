class Select_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();

        // Parse options if passed as JSON string
        if (typeof this.args.options === 'string') {
            try {
                // Decode HTML entities before parsing JSON
                const decoded = decode_html_entities(this.args.options);
                this.args.options = json_decode(decoded);
            } catch (e) {
                console.error('Failed to parse options JSON:', e);
                this.args.options = [];
            }
        }

        // Convert object options to array format
        if (this.args.options && typeof this.args.options === 'object' && !is_array(this.args.options)) {
            this.args.options = Object.entries(this.args.options).map(([value, label]) => ({value, label}));
        }

        this.tom_select = null;
    }

    /**
     * Read the selection from Tom Select, or from the bare <select> before it exists. A
     * subclass that holds its value elsewhere replaces this.
     */
    @Replaceable
    _get_value() {
        if (this.tom_select) {
            return this.tom_select.getValue();
        }
        return this.$sid('input').val();
    }

    /**
     * Select a value in Tom Select, or in the bare <select> before it exists. A subclass
     * that holds its value elsewhere replaces this.
     */
    @Replaceable
    _set_value(value) {
        // Convert to string for TomSelect option matching (handles numeric IDs)
        const str_value = value == null ? '' : str(value);
        if (this.tom_select) {
            this.tom_select.setValue(str_value, true);
        } else if (this.$sid('input').exists()) {
            this.$sid('input').val(str_value);
        }
    }

    on_ready() {
        // A reload() renders again and calls this again: the previous instance goes first, or
        // its dropdown (parented to <body>) is left behind.
        if (this.tom_select) {
            this.tom_select.destroy();
            this.tom_select = null;
        }

        this.tom_select = new TomSelect(this.$sid('input').get(0), this._tom_select_config());

        this._mark_ready();
    }

    /**
     * The Tom Select configuration on_ready() builds the widget from. A subclass searching a
     * remote source replaces it; its onChange must still call this._notify_input(value).
     *
     * @returns {Object}
     */
    @Replaceable
    _tom_select_config() {
        const that = this;

        return {
            placeholder: this.args.placeholder || '',
            allowEmptyOption: true,
            create: false,
            maxOptions: null,
            plugins: ['dropdown_input'],
            dropdownParent: 'body',
            onInitialize: function() {
                this.control.classList.add('form-select');
            },
            onDropdownOpen: function() {
                const dropdown_input = this.dropdown.querySelector('.dropdown-input');
                if (dropdown_input) {
                    dropdown_input.placeholder = 'Search...';
                }
            },
            onChange: function(value) {
                that._notify_input(value);
            }
        };
    }

    on_stop() {
        if (this.tom_select) {
            this.tom_select.destroy();
        }
    }
}
