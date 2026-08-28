/**
 * Form_Field_Abstract
 *
 * Abstract base class for form field wrappers. Provides core functionality without
 * visual formatting. See form_field_abstract.jqhtml for full documentation.
 *
 * A Field is PURELY PRESENTATIONAL: label, required mark, help text, and the
 * accessibility wiring between the two. It renders no error markup of its own - the
 * form's one error renderer (Form_Utils) pins messages under the fields it targets,
 * so there is exactly one styling path for a failed submit.
 *
 * A Field does not need to be inside a form either: a bare Field is a legitimate way
 * to lay out an input outside a submission context.
 *
 * NAME ATTRIBUTE FLOW:
 * - $name is passed to the INPUT component: <Text_Input $name="email" />
 * - Form_Input_Abstract.on_create() stamps data-name on the input's root element
 * - Form_Field reads data-name from its child to know the field name
 * - Form_Field does NOT set data-name - the input owns its name
 */
class Form_Field_Abstract extends Component {
    on_create() {
        // Field name is read from the child input in on_ready().
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

        // $required is PRESENTATION - the asterisk telling the user a SERVER rule
        // exists. It enforces nothing here, because validation lives on the server,
        // once (see Rsx_Form's docblock for the reasoning).

        // Wire label 'for' attribute to input element for accessibility
        let $input = this.$.find('input, select, textarea').first();
        if ($input.exists()) {
            let $label = this.$sid('form_label');
            if ($label.exists() && $input.attr('id')) {
                $label.attr('for', $input.attr('id'));
            }
        }
    }

    /**
     * The input component this field wraps, or null before it renders.
     *
     * @returns {Component|null}
     */
    get_input() {
        const $input_component = this.$.find('.Form_Input_Abstract').first();
        return $input_component.exists() ? $input_component.component() : null;
    }

    async seed() {
        if (!this.args.seeder) {
            return;
        }

        const input = this.get_input();
        if (!input) {
            return;
        }

        // Call the seeder endpoint (an Ajax route reference)
        try {
            let value = await Ajax.call(this.args.seeder, {});
            input.val(value);
        } catch (error) {
            console.error(`Seeder error for ${this._field_name}:`, error);
        }
    }
}
