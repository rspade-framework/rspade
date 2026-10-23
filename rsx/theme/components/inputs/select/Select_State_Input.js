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

        // The state the form asked for (see _get_value()), and whether a country has been applied
        this._wanted = '';
        this._country_loaded = false;

        // Call parent to initialize Select_Ajax_Input
        super.on_create();
    }

    on_ready() {
        // Call parent to initialize Tom Select and _mark_ready()
        super.on_ready();
        this.__show_wanted();
    }

    /**
     * The value is the state the form asked for, held in _wanted - not only what the
     * current option list can show. Until the country arrives the list is empty, and a
     * seeded 'WA' must still read back as 'WA' (and still be what a submit sends).
     */
    _get_value() {
        return this._wanted;
    }

    /**
     * Remember the pick per country, so switching country and back restores it.
     *
     * The cache hangs off _set_value(), NOT off val(): val() is the base class's -
     * buffering, events and the baseline live there, and an input that needs different
     * write behaviour expresses it here.
     *
     * @param {*} value
     */
    _set_value(value) {
        this._wanted = value === null || value === undefined ? '' : str(value);
        if (this.args.country_code && this._wanted && this._wanted !== 'N/A') {
            this._state_cache[this.args.country_code] = this._wanted;
        }
        this.__show_wanted();
    }

    /** Select _wanted in the widget when the current list has it; enable or disable to match. */
    __show_wanted() {
        if (!this.tom_select) {
            return;
        }
        const in_list = this.tom_select.options[this._wanted] !== undefined;
        this.tom_select.setValue(in_list ? this._wanted : '', true);

        const is_na = this.data.select_values.length === 1 && this.data.select_values[0].value === 'N/A';
        if (!this.args.country_code || is_na || this.args.disabled) {
            this.tom_select.disable();
        } else {
            this.tom_select.enable();
        }
    }

    /**
     * Set country code and reload state list.
     *
     * $by_user says whether the USER picked the country. Only then is a resulting change of
     * state the user's change too (announced, so the form sees it); a country applied from
     * data leaves the state the data gave.
     *
     * @param {string} country_code - ISO country code (e.g., 'US', 'CA')
     * @param {boolean} by_user
     */
    async set_country_code(country_code, by_user = false) {
        country_code = country_code || '';
        if (country_code === this.args.country_code && this._country_loaded) {
            return;
        }

        // Remember the pick for the country being left, so coming back restores it
        const leaving = this.args.country_code;
        if (leaving && this._wanted && this._wanted !== 'N/A') {
            this._state_cache[leaving] = this._wanted;
        }

        this.args.country_code = country_code;
        this._country_loaded = true;

        // Reload the component to trigger on_load with new country_code
        await this.alive(this.reload());

        const is_na = this.data.select_values.length === 1 && this.data.select_values[0].value === 'N/A';
        const has = (value) => this.data.select_values.some((opt) => str(typeof opt === 'object' ? opt.value : opt) === value);

        let next = this._wanted;
        if (is_na) {
            next = 'N/A';
        } else if (!has(next)) {
            const cached = this._state_cache[country_code];
            next = by_user ? (cached && has(cached) ? cached : '') : next;
        }

        if (next === this._wanted) {
            this.__show_wanted();
        } else if (by_user) {
            this._set_value(next);
            this._notify_input(next);
        } else {
            this.val(next);
        }
    }
}
