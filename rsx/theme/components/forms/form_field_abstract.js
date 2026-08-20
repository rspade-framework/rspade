/**
 * Form_Field_Abstract
 *
 * Abstract base class for form field wrappers. Provides core functionality without visual formatting.
 * See form_field_abstract.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Discovers child Form_Input_Abstract component
 * - Reads data-name from child input (set by Form_Input_Abstract.on_create())
 * - Wires label 'for' attribute to input element ID for accessibility (if label exists)
 * - Bridges between form validation state and child input
 *
 * NAME ATTRIBUTE FLOW:
 * - $name is passed to the INPUT component: <Text_Input $name="email" />
 * - Form_Input_Abstract.on_create() sets data-name on the input's root element
 * - Form_Field reads data-name from child to know the field name for error display
 * - Form_Field does NOT set data-name - the input owns its name
 */
class Form_Field_Abstract extends Component {
    on_create() {
        // Find parent form for error display
        this.form = this.closest('.Rsx_Form');
        if (!this.form) {
            shouldnt_happen('Form_Field_Abstract must be inside a Rsx_Form component');
        }

        // Field name will be read from child input in on_ready()
        this._field_name = null;
    }

    on_ready() {
        // Find child input component
        let $input_component = this.$.find('.Form_Input_Abstract').first();

        if (!$input_component.exists()) {
            shouldnt_happen(
                `Form_Field_Abstract has no Form_Input_Abstract child. Every Form_Field must contain exactly one input component (Text_Input, Select_Input, etc.)`
            );
        }

        // Read data-name from child input (set by Form_Input_Abstract.on_create())
        this._field_name = $input_component.attr('data-name');

        if (!this._field_name) {
            shouldnt_happen(
                `Form_Field_Abstract child input has no data-name attribute. ` +
                `Pass $name to the input component: <Text_Input $name="field_name" />`
            );
        }

        // Wire label 'for' attribute to input element for accessibility
        let $input = this.$.find('input, select, textarea').first();
        if ($input.exists()) {
            let $label = this.$sid('form_label');
            if ($label.exists() && $input.attr('id')) {
                $label.attr('for', $input.attr('id'));
            }
        }
    }

    get_error() {
        return this.form.get_error(this._field_name);
    }

    has_error() {
        return !!this.get_error();
    }

    async seed() {
        if (!this.args.seeder) {
            return;
        }

        // Find child input component
        let $input_component = this.$.find('.Form_Input_Abstract').first();
        if (!$input_component.exists()) {
            return;
        }

        let input = $input_component.component();
        if (!input || !input.val) {
            return;
        }

        // Call the seeder endpoint (Ajax route reference like form $action)
        try {
            let value = await Ajax.call(this.args.seeder, {});
            input.val(value);
        } catch (error) {
            console.error(`Seeder error for ${this._field_name}:`, error);
        }
    }
}
