class Select_Country_Input extends Select_Ajax_Input {
    async on_load() {
        // Load countries via Ajax endpoint if data not provided
        if (!this.args.data) {
            this.data.select_values = await Rsx_Reference_Data_Controller.countries();
        } else {
            // Use parent on_load for custom data endpoints
            await super.on_load();
        }

        // Reorder select_values to put default country first in the list
        if (this.args.default_country && this.data.select_values && is_array(this.data.select_values)) {
            const default_country_code = this.args.default_country;

            // Find the default country in the array
            const default_country_index = this.data.select_values.findIndex(
                opt => (typeof opt === 'object' ? opt.value : opt) === default_country_code
            );

            if (default_country_index !== -1) {
                // Remove it from its current position
                const [default_country] = this.data.select_values.splice(default_country_index, 1);

                // Add it to the beginning
                this.data.select_values.unshift(default_country);
            }
        }
    }

    on_create() {
        // Set default placeholder if not provided
        if (!this.args.placeholder) {
            this.args.placeholder = 'Select Country...';
        }

        // Call parent to initialize Select_Ajax_Input
        super.on_create();

        // Handle default country value
        if (this.args.default_country && !this.data.value) {
            this.data.value = this.args.default_country;
        }
    }

    on_ready() {
        // Call parent to initialize Tom Select and _mark_ready()
        super.on_ready();

        // Update state selector with initial/default country
        this._update_state_selector();

        // Listen for country changes and update state selector
        if (this.tom_select) {
            this.tom_select.on('change', () => {
                this._update_state_selector();
            });
        }
    }

    /**
     * Find Select_State_Input sibling and update its country code
     * @private
     */
    _update_state_selector() {
        const current_country = this.val();

        // Find Select_State_Input component using closest_sibling
        const state_component = this.$.closest_sibling('.Select_State_Input').component();

        if (state_component && typeof state_component.set_country_code === 'function') {
            state_component.set_country_code(current_country);
        }
    }
}
