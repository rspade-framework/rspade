/**
 * List_Page_Actions - the page-header buttons of a list page (see list_page_actions.jqhtml).
 *
 * The Export button runs the $on_export handler the action passed in, so the action keeps
 * the export logic and this component only draws the button.
 */
class List_Page_Actions extends Component {
    export_clicked() {
        this.args.on_export();
    }
}
