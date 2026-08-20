/**
 * Rsx_Tab
 *
 * Individual tab pane component that works with Rsx_Tabs for form validation.
 * See rsx_tab.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Auto-registers with parent Rsx_Tabs component on creation
 * - Sets tab pane ID attribute from $id argument
 * - Discovers and tracks child Form_Field components
 * - Counts validation errors within this tab's fields
 * - Provides error count to parent for badge display
 */
class Rsx_Tab extends Component {
    on_create() {
        let that = this;

        // Set the tab ID dynamically from args
        if (that.args.id) {
            that.$.attr('id', that.args.id);
        }

        // Find parent Rsx_Tabs and register
        that.tabs_container = that.closest('.Rsx_Tabs');
        if (that.tabs_container) {
            that.tabs_container.register_tab(that);
        }

        // Store reference to all Form_Field components within this tab
        that.fields = [];
    }

    on_ready() {
        let that = this;

        // Find all Form_Field components within this tab
        that.$.find('.Form_Field').each((index, element) => {
            const field_component = $(element).component();
            if (field_component) {
                that.fields.push(field_component);
            }
        });
    }

    /**
     * Count validation errors in Form_Field components within this tab
     * @param {Object} errors - Error object from form validation {field_name: error_message}
     * @returns {number} Count of errors in this tab
     */
    count_errors(errors) {
        let that = this;
        let count = 0;

        for (let field of that.fields) {
            if (errors[field.args.name]) {
                count++;
            }
        }

        return count;
    }
}
