/**
 * Icon_Viewer - see Icon_Viewer.jqhtml for the contract. The terminal fallthrough viewer.
 */
class Icon_Viewer extends Component {
    on_ready() {
        this.trigger('preview_loaded', { pages: 1 });
    }

    set_page() { /* single page - no-op */ }

    get_page() {
        return 1;
    }

    get_pages() {
        return 1;
    }
}
