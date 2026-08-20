/**
 * Page_Scaffold
 *
 * View-page shell owning max-width, page padding, and the main/sidebar column
 * split. See page_scaffold.jqhtml for the full contract.
 *
 * JavaScript responsibilities:
 * - Stamp the column-ratio modifier from $ratio (default 8/4).
 * - Detect an empty sidebar slot and collapse the main column to full width.
 */
class Page_Scaffold extends Component {
    on_create() {
        // Stamp the ratio modifier so the grid picks the right column split.
        // Done in JS (not an interpolated Define class) to avoid the
        // attribute-interpolation pitfall on the root tag.
        const ratio = str(this.args.ratio || '8/4').replace('/', '-');
        this.$.addClass(`Page_Scaffold--ratio-${ratio}`);
    }

    on_render() {
        // Sidebar is optional: when the slot rendered nothing, drop the column
        // so main spans the full width. Idempotent - on_render may re-fire.
        const has_sidebar = this.$sid('sidebar').children().length > 0;
        this.$.toggleClass('Page_Scaffold--no-sidebar', !has_sidebar);
    }
}
