class Select_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();

        // Parse options if passed as JSON string
        if (typeof this.args.options === 'string') {
            try {
                // Decode HTML entities before parsing JSON
                const decoded = $('<textarea>').html(this.args.options).text();
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

    _get_value() {
        if (this.tom_select) {
            return this.tom_select.getValue();
        }
        return this.$sid('input').val();
    }

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
        const that = this;

        // Initialize Tom Select
        let config = {
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

        this.tom_select = new TomSelect(this.$sid('input').get(0), config);

        this._mark_ready();
    }

    async seed() {
        if (this.args.seeder) {
            // TODO: Implement Rsx_Random_Values endpoint
            let value = 'Test ' + (this.args.seeder || 'Value');
            this.val(value);
        } else if (this.args.options && this.args.options.length > 0) {
            // Select random option
            let random_index = Math.floor(Math.random() * this.args.options.length);
            let random_opt = this.args.options[random_index];
            let random_value = typeof random_opt === 'object' ? random_opt.value : random_opt;
            this.val(random_value);
        }
    }

    on_stop() {
        if (this.tom_select) {
            this.tom_select.destroy();
        }
    }
}
