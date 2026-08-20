class Select_Ajax_Input extends Select_Input {
    on_create() {
        super.on_create();

        // Initialize empty select values array
        this.data.select_values = [];
    }

    async on_load() {
        // Load options from Ajax endpoint if provided
        if (this.args.data) {
            try {
                const response = await fetch(this.args.data);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();
                this.data.select_values = data;
            } catch (error) {
                console.error('Failed to load select options:', error);
                this.data.select_values = [];
            }
        }
    }

    // Inherits _get_value() and _set_value() from Select_Input
    // Inherits on_ready() with _mark_ready() from Select_Input
}
