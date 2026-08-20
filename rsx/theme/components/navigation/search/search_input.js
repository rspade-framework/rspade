/**
 * Search_Input - Search input component with customizable placeholder
 *
 * Arguments:
 * - $placeholder (string, optional): Override placeholder text (default in HTML: "Search...")
 */
class Search_Input extends Component {
    on_create() {
        if (this.args.placeholder) {
            this.$.attr('placeholder', this.args.placeholder);
        }
    }
}
