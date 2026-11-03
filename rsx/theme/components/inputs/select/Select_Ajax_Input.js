class Select_Ajax_Input extends Select_Input {
    on_create() {
        super.on_create();

        // Initialize empty select values array
        this.data.select_values = [];
    }

    /**
     * Load the option list from $source, a controller Ajax endpoint
     * (Controller.method), through the house Ajax transport. A failure is not
     * caught: it reaches the framework's error handling like any other Ajax call.
     * Subclasses that know their own endpoint override on_load() instead.
     */
    async on_load() {
        if (this.args.source) {
            this.data.select_values = await Ajax.call(this.args.source, {});
        }
    }

    // Inherits _get_value() and _set_value() from Select_Input
    // Inherits on_ready() with _mark_ready() from Select_Input
}
