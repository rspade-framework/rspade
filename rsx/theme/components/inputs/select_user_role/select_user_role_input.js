class Select_User_Role_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();
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
        this._update_description(str_value);
    }

    on_ready() {
        const that = this;

        let config = {
            placeholder: this.args.placeholder || 'Select a role...',
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
                that._update_description(value);
                that._notify_input(value);
            }
        };

        this.tom_select = new TomSelect(this.$sid('input').get(0), config);

        this._mark_ready();

        // Update description for initial value
        this._update_description(this.val());
    }

    _update_description(value) {
        const description = this.description_map[value] || '';
        this.$sid('description').text(description);
    }

    on_stop() {
        if (this.tom_select) {
            this.tom_select.destroy();
        }
    }
}
