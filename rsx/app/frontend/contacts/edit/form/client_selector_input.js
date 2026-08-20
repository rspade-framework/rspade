class Client_Selector_Input extends Select_Ajax_Input {
    async on_load() {
        // Load clients via Ajax endpoint if data not provided
        if (!this.args.data) {
            this.data.select_values = await Frontend_Contacts_Controller.get_clients();
        } else {
            // Use parent on_load for custom data endpoints
            await super.on_load();
        }
    }

    on_create() {
        // Set default placeholder if not provided
        if (!this.args.placeholder) {
            this.args.placeholder = 'Select Client...';
        }

        // Call parent to initialize Select_Ajax_Input
        super.on_create();
    }

    on_ready() {
        // Call parent to initialize Tom Select
        super.on_ready();
    }
}
