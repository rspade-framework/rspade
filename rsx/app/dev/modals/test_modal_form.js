/**
 * Test_Modal_Form
 *
 * Simple test form component demonstrating modal form functionality.
 */
class Test_Modal_Form extends Component {
    on_create() {
        // Initialize with provided data or empty object
        this.data.values = this.args.data || {};
    }

    on_ready() {
        // Set initial values if provided
        if (this.data.values) {
            this.vals(this.data.values);
        }
    }

    /**
     * Get or set form values
     * @param {Object} values - Optional values to set
     * @returns {Object|null} Returns values if getting, null if setting
     */
    vals(values) {
        if (values) {
            // Setter
            if (values.name !== undefined) {
                this.$sid('name_input').val(values.name);
            }
            if (values.email !== undefined) {
                this.$sid('email_input').val(values.email);
            }
            if (values.role !== undefined) {
                this.$sid('role_select').val(values.role);
            }
            return null;
        } else {
            // Getter
            return {
                name: this.$sid('name_input').val(),
                email: this.$sid('email_input').val(),
                role: this.$sid('role_select').val(),
            };
        }
    }
}
