/**
 * External_Link
 *
 * Link to external website with automatic protocol handling.
 * See external_link.jqhtml for full documentation.
 *
 * JavaScript Responsibilities:
 * - Converts short URL to full URL for href attribute
 * - Sets href dynamically based on args.href
 */
class External_Link extends Component {
    on_create() {
        // Convert short URL to full URL for the href attribute
        const full_url = short_url_to_full_url(this.args.href);

        // Set the href attribute on the component element
        this.$.attr('href', full_url);
    }
}
