/**
 * Rsx_Tab
 *
 * Individual tab pane component that works with Rsx_Tabs for form validation.
 * See rsx_tab.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Auto-registers with parent Rsx_Tabs component on creation
 * - Sets tab pane ID attribute from $id argument
 * - Counts validation errors within this tab's Form_Field components
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
    }

    /**
     * Count validation errors in Form_Field components within this tab
     * @param {Object} errors - Error object from form validation {field_name: error_message}
     * @returns {number} Count of errors in this tab
     */
    count_errors(errors) {
        // Read at call time, not cached at on_ready(): a re-render of this tab's
        // content replaces its Form_Field instances. A field's name lives on its
        // input; Form_Field_Abstract copies it into _field_name.
        let count = 0;

        // Every Form_Field_Abstract is a component with a _field_name by the time a
        // submit can fail (its on_ready() throws without one).
        this.$.find('.Form_Field_Abstract').each((index, element) => {
            if (errors[$(element).component()._field_name]) {
                count++;
            }
        });

        return count;
    }
}
