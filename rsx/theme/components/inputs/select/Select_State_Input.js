class Select_State_Input extends Select_Ajax_Input {
    async on_load() {
        // Load states via Ajax endpoint if country code is set
        if (this.args.country_code) {
            this.data.select_values = await Rsx_Reference_Data_Controller.states({ country: this.args.country_code });

            // If no states returned, add N/A option
            if (!this.data.select_values || this.data.select_values.length === 0) {
                this.data.select_values = [{ value: 'N/A', label: 'N/A' }];
            }
        } else {
            this.data.select_values = [];
        }
    }

    on_create() {
        // Set default placeholder if not provided
        if (!this.args.placeholder) {
            this.args.placeholder = 'Select State...';
        }

        // Set default country code in args if not provided
        if (!this.args.country_code) {
            this.args.country_code = '';
        }

        // Initialize data for select values (loaded in on_load)
        this.data.select_values = [];

        // Cache for selected state per country code
        this._state_cache = {};

        // Call parent to initialize Select_Ajax_Input
        super.on_create();
    }

    on_ready() {
        // Call parent to initialize Tom Select and _mark_ready()
        super.on_ready();

        // Check if this is N/A case (no states for country)
        const is_na = this.data.select_values.length === 1 && this.data.select_values[0].value === 'N/A';

        // Disable if no country code or N/A case
        if ((!this.args.country_code || is_na) && this.tom_select) {
            this.tom_select.disable();
        }

        // Set value to N/A if that's the only option
        if (is_na) {
            this.val('N/A');
        }
    }

    /**
     * Set country code and reload state list
     * @param {string} country_code - ISO country code (e.g., 'US', 'CA')
     */
    async set_country_code(country_code) {
        // Cache the current value for the current country before switching
        const current_country = this.args.country_code;
        const current_value = this.val();
        if (current_country && current_value && current_value !== 'N/A') {
            this._state_cache[current_country] = current_value;
        }

        // Update country code in args and reload
        this.args.country_code = country_code;

        // Reload the component to trigger on_load with new country_code
        await this.reload();

        // Check if this is N/A case
        const is_na = this.data.select_values.length === 1 && this.data.select_values[0].value === 'N/A';

        if (is_na) {
            // Set to N/A and keep disabled
            this.val('N/A');
            if (this.tom_select) {
                this.tom_select.disable();
            }
        } else {
            // Try to restore cached value for this country
            const cached_value = this._state_cache[country_code];

            if (cached_value) {
                const value_exists = this.data.select_values.some((opt) => (typeof opt === 'object' ? opt.value : opt) === cached_value);

                if (value_exists) {
                    this.val(cached_value);
                } else {
                    this.val(''); // Clear if cached value not in new list
                }
            } else {
                this.val(''); // No cached value - leave empty
            }

            // Enable the widget now that we have a country with states
            if (this.tom_select) {
                this.tom_select.enable();
            }
        }
    }

    /**
     * Remember the pick per country, so switching country and back restores it.
     *
     * The cache hangs off _set_value(), NOT off val(): val() is the base class's -
     * buffering, events and the pending-value logic live there, and an input that
     * needs different write behaviour expresses it here.
     *
     * @param {*} value
     */
    _set_value(value) {
        if (this.args.country_code && value && value !== 'N/A') {
            this._state_cache[this.args.country_code] = value;
        }
        super._set_value(value);
    }
}
